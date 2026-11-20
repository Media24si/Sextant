<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.2).
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('hostname', 253)->nullable();
            $table->string('provider', 100)->nullable();
            $table->foreignId('os_choice_id')->nullable()->constrained('choices')->nullOnDelete();
            $table->string('os_reported', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('versions_url', 500)->nullable();
            $table->text('versions_token')->nullable();
            $table->char('versions_token_last4', 4)->nullable();
            $table->string('status', 10)->default('unknown');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique among non-deleted servers (SPEC §3.17).
            $table->string('active_name', 100)->nullable()
                ->storedAs('IF(deleted_at IS NULL, name, NULL)')
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
