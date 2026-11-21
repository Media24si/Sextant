<?php

namespace App\Data\Incidents;

use App\Data\Monitors\MonitorResourceData;
use App\Enums\Status;
use Spatie\LaravelData\Data;

/**
 * An incident in a list (SPEC §13.6). `state` is open, acknowledged or
 * resolved (§3.11). Times are ISO 8601 UTC.
 */
class IncidentRowData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public Status $severity,
        /** open, acknowledged or resolved */
        public string $state,
        public string $message,
        public MonitorResourceData $resource,
        public string $checker,
        public string $opened_at,
        public ?string $resolved_at,
        public ?string $resolution,
        public ?string $acknowledged_by,
        public ?int $parent_id,
        public int $children_count,
        /** Open, with passing runs since the last failure (§6.1). */
        public bool $recovering,
        public bool $muted,
    ) {}
}
