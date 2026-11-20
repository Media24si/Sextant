<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.6).
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name', 253);
            $table->date('expires_at')->nullable();
            $table->string('registrar')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('unknown');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->string('active_name', 253)->nullable()
                ->storedAs('IF(deleted_at IS NULL, name, NULL)')
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
