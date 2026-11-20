<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each server's backups are stored (SPEC §3.4).
     */
    public function up(): void
    {
        Schema::create('server_backup_location', function (Blueprint $table) {
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('choice_id')->constrained()->restrictOnDelete();

            $table->primary(['server_id', 'choice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_backup_location');
    }
};
