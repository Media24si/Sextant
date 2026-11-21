<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/** Add / edit monitor. `arguments` and thresholds hold overrides only; null = inherit (§4.5). */
class MonitorFormData extends Data
{
    public function __construct(
        public ?int $id,
        public string $monitorable_type,
        public int $monitorable_id,
        public string $checker,
        public ?string $label,
        /** @var array<string, mixed> */
        public array $arguments,
        public ?int $interval_minutes,
        public ?int $failures_before_incident,
        public ?int $successes_before_resolve,
        public bool $enabled,
    ) {}
}
