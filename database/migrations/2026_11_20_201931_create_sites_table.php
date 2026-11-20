<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations (SPEC §3.7).
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            $table->string('hostname', 253);
            $table->string('scheme', 5)->default('https');
            $table->unsignedSmallInteger('port')->nullable();
            $table->foreignId('front_service_id')->nullable()->constrained('external_services')->restrictOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 500)->nullable();
            $table->string('github_url')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 10)->default('unknown');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->string('active_hostname', 253)->nullable()
                ->storedAs('IF(deleted_at IS NULL, hostname, NULL)')
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
