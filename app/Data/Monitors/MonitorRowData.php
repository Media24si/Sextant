<?php

namespace App\Data\Monitors;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/**
 * A monitor in a list (SPEC §13.6). `pending`: the latest run failed but no
 * incident is open (§6.1). Times are ISO 8601 UTC.
 */
class MonitorRowData extends Data
{
    public function __construct(
        public int $id,
        public string $checker,
        /** e.g. "TCP port · SSH (22)" */
        public string $name,
        public MonitorResourceData $resource,
        public bool $enabled,
        public ?string $muted_until,
        public Status $status,
        public bool $pending,
        public ?Status $last_result_status,
        public ?string $message,
        public ?int $duration_ms,
        public ?string $checked_at,
        public ?string $next_run_at,
        public int $interval,
    ) {}
}
