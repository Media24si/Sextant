<?php

namespace App\Monitoring;

use App\Enums\Status;
use App\Models\MonitorState;
use Carbon\CarbonInterface;

/**
 * A monitor's effective status (SPEC §6.1): every non-OK status is backed by
 * an open incident; a failure that hasn't opened one yet shows as Pending.
 */
class EffectiveStatus
{
    public static function of(MonitorState $state, CarbonInterface $now, ?Status $openIncidentSeverity = null): Status
    {
        return match (true) {
            $openIncidentSeverity !== null => $openIncidentSeverity,
            $state->last_definitive_at === null => Status::Unknown,
            $state->stale_at !== null && $state->stale_at->lt($now) => Status::Unknown,
            default => Status::Ok,
        };
    }
}
