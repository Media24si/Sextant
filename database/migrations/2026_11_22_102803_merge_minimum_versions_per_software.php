<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One minimum per software with a version per branch (SPEC §3.15). PHP's
     * per-branch rows are merged into one; the first row keeps its CVE link,
     * note and author.
     */
    public function up(): void
    {
        Schema::table('minimum_versions', function (Blueprint $table) {
            $table->json('min_versions')->nullable()->after('software');
        });

        DB::table('minimum_versions')->orderBy('id')->get()->groupBy('software')->each(function ($rows): void {
            $versions = $rows->pluck('min_version')->unique()->sort(fn (string $a, string $b): int => version_compare($a, $b))->values()->all();
            $first = $rows->first();

            DB::table('minimum_versions')->where('id', $first->id)->update(['min_versions' => json_encode($versions)]);
            DB::table('minimum_versions')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
        });

        Schema::table('minimum_versions', function (Blueprint $table) {
            $table->dropUnique(['software', 'branch']);
            $table->dropColumn(['branch', 'min_version']);
            $table->unique('software');
        });

        Schema::table('minimum_versions', function (Blueprint $table) {
            $table->json('min_versions')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations: PHP gets a row per branch again, the others keep
     * their lowest version.
     */
    public function down(): void
    {
        Schema::table('minimum_versions', function (Blueprint $table) {
            $table->dropUnique(['software']);
            $table->string('branch', 20)->default('')->after('software');
            $table->string('min_version', 50)->default('')->after('branch');
        });

        foreach (DB::table('minimum_versions')->orderBy('id')->get() as $row) {
            /** @var list<string> $versions */
            $versions = json_decode($row->min_versions, true);

            if ($row->software !== 'php') {
                DB::table('minimum_versions')->where('id', $row->id)->update(['min_version' => $versions[0]]);

                continue;
            }

            foreach ($versions as $index => $version) {
                $branch = implode('.', array_slice(explode('.', $version), 0, 2));

                if ($index === 0) {
                    DB::table('minimum_versions')->where('id', $row->id)->update(['branch' => $branch, 'min_version' => $version]);
                } else {
                    DB::table('minimum_versions')->insert([
                        ...collect((array) $row)->except('id')->all(),
                        'branch' => $branch,
                        'min_version' => $version,
                    ]);
                }
            }
        }

        Schema::table('minimum_versions', function (Blueprint $table) {
            $table->dropColumn('min_versions');
            $table->unique(['software', 'branch']);
        });
    }
};
