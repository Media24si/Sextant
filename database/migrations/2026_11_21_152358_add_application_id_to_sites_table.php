<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.7).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('domain_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_id');
        });
    }
};
