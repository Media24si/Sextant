<?php

namespace App\Data\Incidents;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/** One entry on an incident's timeline (SPEC §3.12). */
class IncidentEventData extends Data
{
    public function __construct(
        public int $id,
        /** opened, escalated, deescalated, acknowledged, reminder or resolved */
        public string $type,
        public Status $severity,
        public string $message,
        public ?string $user,
        public bool $notify,
        public ?string $notified_at,
        public ?string $suppressed_reason,
        public string $created_at,
    ) {}
}
