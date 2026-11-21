<?php

namespace App\Monitoring;

use App\Enums\Status;
use App\Models\MonitorState;
use Carbon\CarbonInterface;

/**
 * A monitor's effective status (SPEC §6.1).
 *
 * SPEC: until incidents exist (Phase 4) there is never an open incident, so a
 * failing monitor stays OK and shows as Pending (§18 Phase 3).
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
