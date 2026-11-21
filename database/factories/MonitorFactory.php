<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * An HTTP monitor on a new site, due now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitorable_type' => 'site',
            'monitorable_id' => Site::factory(),
            'checker' => 'site.http',
            'label' => null,
            'arguments' => null,
            'enabled' => true,
            'next_run_at' => now(),
        ];
    }

    public function on(Server|Domain|Site $resource, string $checker): static
    {
        return $this->state([
            'monitorable_type' => $resource->getMorphClass(),
            'monitorable_id' => $resource->id,
            'checker' => $checker,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false, 'next_run_at' => null]);
    }
}
