<?php

namespace App\Data\Monitors;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/** One run in the monitor drawer. */
class MonitorRunData extends Data
{
    public function __construct(
        public int $id,
        public Status $status,
        public int $duration_ms,
        public ?string $message,
        public string $checked_at,
    ) {}
}
