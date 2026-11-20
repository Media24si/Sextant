<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'web'.fake()->unique()->numberBetween(10, 9999);

        return [
            'name' => $name,
            'hostname' => $name.'.media24.si',
            'provider' => fake()->randomElement(['Hetzner', 'DigitalOcean', 'Arnes', null]),
        ];
    }

    /**
     * With a primary IPv4 address.
     */
    public function withPrimaryAddress(?string $ip = null): static
    {
        return $this->has(
            ServerAddress::factory()->primary()->state(['ip' => $ip ?? fake()->unique()->ipv4()]),
            'addresses',
        );
    }
}
