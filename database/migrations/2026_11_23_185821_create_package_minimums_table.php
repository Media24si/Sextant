<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The lowest safe version of a package, one per major (SPEC §3.23),
     * e.g. laravel/framework `12.13, 13.39`.
     */
    public function up(): void
    {
        Schema::create('package_minimums', function (Blueprint $table) {
            $table->id();
            $table->string('ecosystem', 10);
            $table->string('name', 214);
            $table->json('min_versions');
            $table->string('cve_url', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->constrained('users');
            $table->timestamps();

            $table->unique(['ecosystem', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_minimums');
    }
};
