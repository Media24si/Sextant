<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The locked package versions of a repository's last read (SPEC §3.22).
     * Written only by PackagesRecorder, which replaces them on every read.
     */
    public function up(): void
    {
        Schema::create('repository_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem', 10);
            $table->string('name', 214);
            $table->string('version', 50);
            $table->boolean('dev')->default(false);
            $table->boolean('direct')->default(false);

            $table->unique(['repository_id', 'ecosystem', 'name', 'version']);
            $table->index(['ecosystem', 'name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_packages');
    }
};
