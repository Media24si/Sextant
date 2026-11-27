<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defaults per checker set in the UI, on top of `config/checkers.php`.
     * Empty columns keep the config value.
     */
    public function up(): void
    {
        Schema::create('checker_settings', function (Blueprint $table) {
            $table->id();
            $table->string('checker', 64)->unique();
            $table->json('arguments')->nullable();
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->unsignedTinyInteger('failures_before_incident')->nullable();
            $table->unsignedTinyInteger('successes_before_resolve')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checker_settings');
    }
};
