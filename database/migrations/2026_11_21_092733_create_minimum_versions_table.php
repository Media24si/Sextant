<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.15).
     */
    public function up(): void
    {
        Schema::create('minimum_versions', function (Blueprint $table) {
            $table->id();
            $table->string('software', 20);
            $table->string('branch', 20)->default('');
            $table->string('min_version', 50);
            $table->string('cve_url')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('set_by')->constrained('users');
            $table->timestamps();

            $table->unique(['software', 'branch']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('minimum_versions');
    }
};
