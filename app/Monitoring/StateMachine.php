<?php

namespace App\Monitoring;

use App\Checks\CheckResult;
use App\Checks\ResolvedSettings;
use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Updates a monitor's counters, opens, updates and resolves its incident, and
 * sets its effective status after a run (SPEC §6.2). Unknown results neither
 * extend nor break a streak.
 */
class StateMachine
{
    public function __construct(private IncidentManager $incidents) {}

    /**
     * @param  Incident|null  $incident  the monitor's open incident, locked
     */
    public function apply(
        Monitor $monitor,
        Model&Monitorable $resource,
        MonitorState $state,
        ?Incident $incident,
        CheckResult $result,
        ResolvedSettings $settings,
        int $durationMs,
        CarbonInterface $now,
    ): StateTransition {
        $previous = $state->status;

        $state->checked_at = $now;
        $state->last_result_status = $result->status;
        $state->message = mb_strimwidth($result->message, 0, 1000, '…');
        $state->duration_ms = $durationMs;
        $recheckSoon = false;
        $opened = false;

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

                if ($incident !== null) {
                    $this->incidents->failedAgain($incident, $result, $now);
                } elseif ($state->consecutive_failures >= $settings->failuresBeforeIncident) {
                    $incident = $this->incidents->open($monitor, $resource, $result, $now);
                    $opened = true;
                } else {
                    // Not an incident yet: confirm a minute later (§5.1).
                    $recheckSoon = true;
                }
            } else {
                $state->consecutive_failures = 0;
                $state->consecutive_successes++;

                if ($incident !== null && $state->consecutive_successes >= $settings->successesBeforeResolve) {
                    $this->incidents->resolve($incident, IncidentResolution::Recovered, $now);
                    $incident = null;
                } elseif ($incident !== null) {
                    // Recovering: confirm a minute later (§5.1).
                    $recheckSoon = true;
                }
            }
        }

        $state->status = EffectiveStatus::of($state, $now, $incident?->severity);

        if ($state->status !== $previous) {
            $state->status_changed_at = $now;
        }

        return new StateTransition($previous, $state->status, $recheckSoon, $opened, $result->isFailure() && $state->consecutive_failures === 1);
    }
}
