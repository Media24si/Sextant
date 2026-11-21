<?php

namespace Database\Factories;

use App\Enums\Software;
use App\Models\MinimumVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MinimumVersion>
 */
class MinimumVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'software' => Software::Nginx,
            'branch' => '',
            'min_version' => '1.26.2',
            'set_by' => User::factory()->admin(),
        ];
    }
}
