<?php

namespace App\Monitoring;

use App\Enums\Status;

/** What a run changed in a monitor's state (SPEC §6.2). */
final readonly class StateTransition
{
    public function __construct(
        public Status $previousStatus,
        public Status $status,
        /** Check again in a minute to confirm (§5.1). */
        public bool $recheckSoon,
    ) {}

    public function statusChanged(): bool
    {
        return $this->previousStatus !== $this->status;
    }
}
