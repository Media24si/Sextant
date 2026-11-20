<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * A subdomain of its domain, served straight from no backend yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'hostname' => fn (array $attributes): string => fake()->unique()->domainWord().'.'.Domain::query()->findOrFail((int) $attributes['domain_id'])->name,
            'scheme' => 'https',
            'port' => null,
            'front_service_id' => null,
            'server_id' => null,
        ];
    }
}
