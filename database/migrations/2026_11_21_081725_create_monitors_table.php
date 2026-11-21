<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.8).
     */
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->morphs('monitorable');
            $table->string('checker', 50);
            $table->string('label', 100)->nullable();
            $table->json('arguments')->nullable();
            $table->unsignedSmallInteger('interval_minutes')->nullable();
            $table->unsignedTinyInteger('failures_before_incident')->nullable();
            $table->unsignedTinyInteger('successes_before_resolve')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enabled', 'next_run_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
