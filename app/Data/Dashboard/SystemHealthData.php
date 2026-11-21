<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/** Sextant's own health, for the header pill and the dashboard footer (SPEC §13.7). */
class SystemHealthData extends Data
{
    public function __construct(
        public ?string $last_dispatch_at,
        /** Decided against the server's clock, so a browser's skewed clock can't say "stopped". */
        public bool $checks_running,
        public int $stale_monitors,
        /** Null when no check failed recently, so the canary didn't run. */
        public ?bool $network_healthy,
    ) {}
}
