<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.14): each server's current versions,
     * written by `server.versions`.
     */
    public function up(): void
    {
        Schema::create('server_software', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('software', 20);
            $table->string('branch', 20)->default('');
            $table->string('version', 50);
            $table->string('package_version', 100)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('read_at');
            $table->timestamp('fetched_at');

            $table->unique(['server_id', 'software', 'branch']);
            $table->index(['software', 'branch', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_software');
    }
};
