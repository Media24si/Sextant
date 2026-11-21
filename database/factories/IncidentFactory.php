<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * An open error on a new site's HTTP monitor.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $result = ['status' => 'error', 'message' => '500 in 80 ms', 'data' => []];

        return [
            'monitor_id' => Monitor::factory(),
            'monitorable_type' => fn (array $attributes): string => Monitor::query()->findOrFail((int) $attributes['monitor_id'])->monitorable_type,
            'monitorable_id' => fn (array $attributes): int => Monitor::query()->findOrFail((int) $attributes['monitor_id'])->monitorable_id,
            'checker' => fn (array $attributes): string => Monitor::query()->findOrFail((int) $attributes['monitor_id'])->checker,
            'title' => 'site — HTTP',
            'severity' => Status::Error,
            'max_severity' => Status::Error,
            'message' => '500 in 80 ms',
            'opened_at' => now(),
            'last_seen_at' => now(),
            'occurrences' => 2,
            'first_result' => $result,
            'last_result' => $result,
        ];
    }

    public function resolved(): static
    {
        return $this->state(['resolved_at' => now(), 'resolution' => 'recovered']);
    }
}
