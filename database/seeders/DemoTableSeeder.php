<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sample users for the temporary DataTable demo (P0.11), local only:
 * php artisan db:seed --class=DemoTableSeeder
 */
class DemoTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo users are for local development only.');
        }

        User::factory()->count(110)->create();
        User::factory()->admin()->count(10)->create();
    }
}
