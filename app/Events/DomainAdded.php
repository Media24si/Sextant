<?php

namespace App\Events;

use App\Data\Detection\ProposedMonitorData;
use App\Models\Domain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A domain was created along with its first sites (SPEC §10.4, §10.5).
 * Like `SiteAdded`, `AttachDefaultMonitors` attaches its default monitors.
 */
class DomainAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<ProposedMonitorData>  $monitors
     * @param  list<string>  $skipCheckers  checkers unticked on Add site
     */
    public function __construct(
        public Domain $domain,
        public array $monitors,
        /** Bulk import: spread first runs over the first interval (§5.1). */
        public bool $spread = false,
        public array $skipCheckers = [],
    ) {}
}
