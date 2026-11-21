<?php

namespace App\Monitoring;

use App\Models\Monitor;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

/**
 * When a site starts failing, check its backend server's TCP ports now, so
 * a server outage opens (and adopts the site) without waiting a full
 * interval (SPEC §6.5). Skipped when they ran in the last minute.
 */
class DependencyChecks
{
    private const RECENT_SECONDS = 60;

    public function __construct(private MonitorClaimer $claimer) {}

    public function checkBackendOf(Model $resource): void
    {
        if (! $resource instanceof Site || $resource->server_id === null) {
            return;
        }

        Monitor::query()
            ->where('monitorable_type', 'server')
            ->where('monitorable_id', $resource->server_id)
            ->where('checker', 'server.tcp')
            ->where('enabled', true)
            ->whereDoesntHave('state', fn ($state) => $state->where('checked_at', '>', now()->subSeconds(self::RECENT_SECONDS)))
            ->get()
            ->each(fn (Monitor $monitor): bool => $this->claimer->claimNow($monitor));
    }
}
