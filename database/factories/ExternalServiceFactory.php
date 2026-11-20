<?php

namespace Database\Factories;

use App\Enums\ExternalServiceKind;
use App\Models\ExternalService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalService>
 */
class ExternalServiceFactory extends Factory
{
    /**
     * A proxy we enter by hand, like the Varnish server.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Varnish '.fake()->unique()->numberBetween(1, 9999),
            'kind' => ExternalServiceKind::Proxy,
            'ranges' => [fake()->unique()->ipv4()],
            'ranges_source' => null,
            'cname_suffixes' => null,
            'response_headers' => null,
        ];
    }
}
