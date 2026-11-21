<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * A resource's open root incidents, with the site incidents grouped under
 * them (SPEC §13.7, §6.5).
 */
class IssueGroupData extends Data
{
    public function __construct(
        public ResourceRefData $resource,
        /** @var list<IncidentItemData> */
        public array $incidents,
        /** @var list<IncidentItemData> */
        public array $children,
    ) {}
}
