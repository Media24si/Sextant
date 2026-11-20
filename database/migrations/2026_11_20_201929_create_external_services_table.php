<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.5), seeded with Cloudflare.
     */
    public function up(): void
    {
        Schema::create('external_services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 10);
            $table->json('ranges');
            $table->string('ranges_source', 20)->nullable();
            $table->json('cname_suffixes')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->string('active_name', 100)->nullable()
                ->storedAs('IF(deleted_at IS NULL, name, NULL)')
                ->unique();
        });

        DB::table('external_services')->insert([
            'name' => 'Cloudflare',
            'kind' => 'cdn',
            'ranges' => '[]',
            'ranges_source' => 'cloudflare',
            'cname_suffixes' => '[".cdn.cloudflare.net"]',
            'response_headers' => '["cf-ray"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_services');
    }
};
