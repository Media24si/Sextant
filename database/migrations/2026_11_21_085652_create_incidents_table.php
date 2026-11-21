<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.11). `open_monitor_id` lets MySQL guarantee
     * at most one open incident per monitor; because it's generated from
     * `monitor_id`, that foreign key can only be RESTRICT (docs/spikes/mysql-9.7.md).
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->restrictOnDelete();
            $table->string('monitorable_type');
            $table->unsignedBigInteger('monitorable_id');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->string('checker', 50);
            $table->string('title');
            $table->string('severity', 10);
            $table->string('max_severity', 10);
            $table->string('message', 1000);
            $table->timestamp('opened_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->json('first_result');
            $table->json('last_result');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('open_monitor_id')->nullable()
                ->storedAs('IF(resolved_at IS NULL, monitor_id, NULL)')
                ->unique();

            $table->index('resolved_at');
            $table->index(['server_id', 'opened_at']);
            $table->index(['monitorable_type', 'monitorable_id', 'opened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
