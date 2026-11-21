<?php

namespace App\Events;

use App\Data\Detection\ProposedMonitorData;
use App\Models\Site;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A site was added through Add site, Add domain or an import (SPEC §10.4).
 *
 * Carries the monitors detection proposed; `AttachDefaultMonitors` attaches
 * them after commit (§4.4) and claims them for an immediate run.
 */
class SiteAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<ProposedMonitorData>  $monitors
     * @param  list<string>  $skipCheckers  checkers unticked on Add site
     */
    public function __construct(
        public Site $site,
        public array $monitors,
        /** Bulk import: spread first runs over the first interval (§5.1). */
        public bool $spread = false,
        public array $skipCheckers = [],
    ) {}
}
