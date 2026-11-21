<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.9): one row per monitor.
     */
    public function up(): void
    {
        Schema::create('monitor_states', function (Blueprint $table) {
            $table->foreignId('monitor_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('unknown');
            $table->timestamp('status_changed_at')->nullable();
            $table->string('last_result_status', 10)->nullable();
            $table->string('message', 1000)->nullable();
            $table->json('data')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('last_definitive_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('consecutive_successes')->default(0);
            $table->unsignedSmallInteger('consecutive_unknowns')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_states');
    }
};
