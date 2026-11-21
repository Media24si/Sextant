<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/** The dashboard's totals row (SPEC §13.7). */
class TotalsData extends Data
{
    public function __construct(
        public StatusCountsData $servers,
        public StatusCountsData $domains,
        public StatusCountsData $sites,
        public int $sites_unassigned,
        /** Filled in by Phase 7 (software versions). */
        public int $servers_below_minimum,
    ) {}
}
