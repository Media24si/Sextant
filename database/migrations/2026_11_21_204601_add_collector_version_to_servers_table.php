<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.2, §11.8).
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedSmallInteger('collector_version')->nullable()->after('os_reported');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('collector_version');
        });
    }
};
