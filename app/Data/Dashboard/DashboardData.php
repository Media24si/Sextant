<?php

namespace App\Data\Dashboard;

use App\Data\ActivityItemData;
use Spatie\LaravelData\Data;

/** Everything the dashboard shows (SPEC §13.7 `DashboardProps`). */
class DashboardData extends Data
{
    public function __construct(
        public TotalsData $totals,
        /** @var list<ServerTileData> */
        public array $servers,
        /** @var list<IssueGroupData> */
        public array $issues,
        /** @var list<IssueGroupData> */
        public array $muted,
        public int $pending_count,
        /** @var list<ResolvedItemData> */
        public array $resolved,
        /** @var list<SoftwareMinimumRowData> */
        public array $software,
        /** @var list<ExpiringItemData> */
        public array $expiring,
        /** @var list<ActivityItemData> */
        public array $changes,
        public SystemHealthData $system,
    ) {}
}
