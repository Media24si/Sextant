<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerAddress>
 */
class ServerAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'ip' => fake()->unique()->ipv4(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => ['is_primary' => true]);
    }
}
