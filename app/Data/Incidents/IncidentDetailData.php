<?php

namespace App\Data\Incidents;

use App\Data\ServingPathData;
use App\Enums\Status;
use Spatie\LaravelData\Data;

/** The incident page (SPEC §13.6): timeline, children, first and last result, links. */
class IncidentDetailData extends Data
{
    public function __construct(
        public IncidentRowData $incident,
        public Status $max_severity,
        public int $occurrences,
        public string $last_seen_at,
        public ?string $acknowledged_at,
        public int $monitor_id,
        public string $monitor_name,
        public bool $monitor_deleted,
        public ?ServingPathData $serving_path,
        public IncidentResultData $first_result,
        public IncidentResultData $last_result,
        /** @var list<IncidentEventData> */
        public array $events,
        public ?IncidentRowData $parent,
        /** @var list<IncidentRowData> */
        public array $children,
    ) {}
}
