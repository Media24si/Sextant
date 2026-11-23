<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub repositories linked from sites and applications, one row per
     * link (SPEC §3.21). Kept in step with the links by RepositorySync;
     * soft-deleted when nothing links to one any more.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 39);
            $table->string('name', 100);
            $table->string('url', 255)->unique();
            $table->string('default_branch', 255)->nullable();
            $table->json('lock_files')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('status', 10)->default('unknown');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
