<?php

namespace App\Monitoring;

use App\Checks\CheckResult;
use App\Checks\ResolvedSettings;
use App\Enums\Status;
use App\Models\MonitorState;
use Carbon\CarbonInterface;

/**
 * Updates a monitor's counters and effective status after a run (SPEC §6.2).
 * Unknown results neither extend nor break a streak.
 *
 * SPEC: incidents arrive in Phase 4. Until then a streak that reaches
 * `failures_before_incident` is where an incident would open; the monitor
 * stays Pending (§18 Phase 3).
 */
class StateMachine
{
    public function apply(MonitorState $state, CheckResult $result, ResolvedSettings $settings, int $durationMs, CarbonInterface $now): StateTransition
    {
        $previous = $state->status;

        $state->checked_at = $now;
        $state->last_result_status = $result->status;
        $state->message = mb_strimwidth($result->message, 0, 1000, '…');
        $state->duration_ms = $durationMs;
        $recheckSoon = false;

        if ($result->status === Status::Unknown) {
            $state->consecutive_unknowns++;
        } else {
            $state->consecutive_unknowns = 0;
            $state->data = $result->data;
            $state->last_definitive_at = $now;
            $state->stale_at = $now->copy()->addMinutes($settings->interval * (int) config('sextant.stale.multiplier') + (int) config('sextant.stale.grace_minutes'));

            if ($result->isFailure()) {
                $state->consecutive_successes = 0;
                $state->consecutive_failures++;
                // A failure that hasn't reached the incident threshold is confirmed a minute later (§5.1).
                $recheckSoon = $state->consecutive_failures < $settings->failuresBeforeIncident;
            } else {
                $state->consecutive_failures = 0;
                $state->consecutive_successes++;
            }
        }

        $state->status = EffectiveStatus::of($state, $now);

        if ($state->status !== $previous) {
            $state->status_changed_at = $now;
        }

        return new StateTransition($previous, $state->status, $recheckSoon);
    }
}
