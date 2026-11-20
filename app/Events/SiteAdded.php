<?php

namespace App\Events;

use App\Data\Detection\ProposedMonitorData;
use App\Models\Site;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A site was added through Add site, Add domain or an import (SPEC §10.4).
 *
 * SPEC: monitors arrive in Phase 3 (P3.1). Until then this event carries the
 * monitors detection proposed; P3's listener attaches them (§4.4) and claims
 * them for an immediate run.
 */
class SiteAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<ProposedMonitorData>  $monitors
     */
    public function __construct(
        public Site $site,
        public array $monitors,
    ) {}
}
