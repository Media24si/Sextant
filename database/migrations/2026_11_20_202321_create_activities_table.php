<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The change feed (SPEC §3.13, §8). Kept indefinitely.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('category', 10);
            $table->string('type', 50);
            $table->morphs('subject');
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('monitor_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary', 500);
            $table->json('properties')->nullable();
            $table->timestamp('created_at');

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['server_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
