<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.12): the incident timeline and the
     * notification outbox.
     */
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('severity', 10);
            $table->string('message', 1000);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('notify');
            $table->timestamp('notified_at')->nullable();
            $table->string('suppressed_reason', 20)->nullable();
            $table->timestamp('created_at');

            $table->index(['notify', 'notified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
