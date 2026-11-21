<?php

namespace App\Listeners;

use App\Events\DomainAdded;
use App\Events\SiteAdded;
use App\Monitoring\DefaultMonitors;

/**
 * Attaches default monitors to sites and domains added through Add site,
 * Add domain and import, with the arguments detection proposed (SPEC §4.4,
 * §10.4). Runs after the save commits.
 */
class AttachDefaultMonitors
{
    public function __construct(private DefaultMonitors $defaults) {}

    public function handleSiteAdded(SiteAdded $event): void
    {
        $this->defaults->attach($event->site, $event->monitors, $event->spread);
    }

    public function handleDomainAdded(DomainAdded $event): void
    {
        $this->defaults->attach($event->domain, $event->monitors, $event->spread);
    }
}
