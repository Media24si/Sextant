<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Models\PackageMinimum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageMinimum>
 */
class PackageMinimumFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ecosystem' => Ecosystem::Composer,
            'name' => 'laravel/framework',
            'min_versions' => ['12.13', '13.39'],
            'set_by' => User::factory()->admin(),
        ];
    }
}
