<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.10). No foreign key, so pruning stays cheap.
     */
    public function up(): void
    {
        Schema::create('monitor_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monitor_id');
            $table->string('status', 10);
            $table->unsignedInteger('duration_ms');
            $table->string('message', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('checked_at');

            $table->index(['monitor_id', 'checked_at']);
            $table->index('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_runs');
    }
};
