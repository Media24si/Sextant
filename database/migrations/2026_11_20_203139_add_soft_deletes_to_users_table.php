<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users are soft-deleted so their past changes keep their name (GOAL
     * "Every change in the feed shows which admin made it"). A deleted user's
     * email is free again, the same way as servers' names (SPEC §3.17).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
            $table->dropUnique(['email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('active_email')->nullable()
                ->storedAs('IF(deleted_at IS NULL, email, NULL)')
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['active_email']);
            $table->dropColumn('active_email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->unique('email');
        });
    }
};
