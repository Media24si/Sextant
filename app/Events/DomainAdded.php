<?php

namespace App\Events;

use App\Data\Detection\ProposedMonitorData;
use App\Models\Domain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A domain was created along with its first sites (SPEC §10.4, §10.5).
 * Like `SiteAdded`, the hook for attaching default monitors in Phase 3.
 */
class DomainAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<ProposedMonitorData>  $monitors
     */
    public function __construct(
        public Domain $domain,
        public array $monitors,
    ) {}
}
