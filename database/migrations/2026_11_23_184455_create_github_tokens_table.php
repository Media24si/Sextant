<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-only GitHub tokens, one per repository owner (SPEC §3.20).
     */
    public function up(): void
    {
        Schema::create('github_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 39)->unique();
            $table->text('token');
            $table->char('token_last4', 4);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->foreignId('set_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_tokens');
    }
};
