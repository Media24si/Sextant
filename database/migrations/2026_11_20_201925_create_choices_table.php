<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-editable lists (SPEC §3.4), seeded with the defaults.
     */
    public function up(): void
    {
        Schema::create('choices', function (Blueprint $table) {
            $table->id();
            $table->string('list', 30);
            $table->string('label', 100);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['list', 'label']);
        });

        $now = now();
        $defaults = [
            'os' => [
                'Ubuntu 22.04 LTS', 'Ubuntu 24.04 LTS', 'Ubuntu 26.04 LTS', 'Debian 12', 'Debian 13',
                'Windows Server 2019', 'Windows Server 2022', 'Windows Server 2025', 'Other',
            ],
            // GOAL open question: the real list of backup locations.
            'backup_location' => ['Provider snapshots', 'Backup server', 'Offsite cloud storage', 'Local disk on the server'],
        ];

        foreach ($defaults as $list => $labels) {
            foreach ($labels as $index => $label) {
                DB::table('choices')->insert([
                    'list' => $list,
                    'label' => $label,
                    'position' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('choices');
    }
};
