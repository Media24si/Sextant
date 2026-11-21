<?php

namespace App\Checks;

use App\Enums\Status;

/** Everything a run needs from the registry and the monitor's overrides (SPEC §4.5). */
final readonly class ResolvedSettings
{
    public function __construct(
        public Arguments $arguments,
        public int $interval,
        public int $failuresBeforeIncident,
        public int $successesBeforeResolve,
        public Status $maxSeverity,
        public bool $requiresNetwork,
        public string $queue,
    ) {}
}
