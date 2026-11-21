<?php

namespace App\Monitoring;

use App\Activity\ActivityRecorder;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\ActivityCategory;
use App\Enums\IncidentEventType;
use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Monitor;
use App\Models\MonitorState;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every change to an incident goes through here (SPEC §6.3): it writes the
 * incident, an event on its timeline (the notification outbox, with `notify`)
 * and a `status` entry in the change feed. Also groups site incidents under
 * their backend server's outage (§6.5).
 */
class IncidentManager
{
    public function __construct(private CheckerRegistry $registry) {}

    /**
     * Open an incident. The unique `open_monitor_id` rejects a second open
     * incident for the same monitor; the existing one is returned instead.
     */
    public function open(Monitor $monitor, Model&Monitorable $resource, CheckResult $result, CarbonInterface $now): Incident
    {
        $snapshot = $this->snapshot($result);

        try {
            $incident = DB::transaction(fn (): Incident => Incident::query()->create([
                'monitor_id' => $monitor->id,
                'monitorable_type' => $monitor->monitorable_type,
                'monitorable_id' => $monitor->monitorable_id,
                'server_id' => $this->serverIdOf($resource),
                'checker' => $monitor->checker,
                'title' => $this->title($monitor, $resource),
                'severity' => $result->status,
                'max_severity' => $result->status,
                'message' => $this->trim($result->message),
                'opened_at' => $now,
                'last_seen_at' => $now,
                'occurrences' => 1,
                'first_result' => $snapshot,
                'last_result' => $snapshot,
            ]));
        } catch (UniqueConstraintViolationException) {
            return Incident::query()->open()->where('monitor_id', $monitor->id)->firstOrFail();
        }

        $this->event($incident, IncidentEventType::Opened, $result->message, notify: true, at: $now);
        $this->feed($incident, 'incident.opened', "{$incident->title}: {$result->message}");
        $this->group($incident, $resource, $now);

        return $incident;
    }

    /**
     * Another failure during an open incident (§6.2): counts, latest result,
     * and escalation or de-escalation when the severity changes.
     */
    public function failedAgain(Incident $incident, CheckResult $result, CarbonInterface $now): void
    {
        $previous = $incident->severity;

        $incident->fill([
            'last_seen_at' => $now,
            'occurrences' => $incident->occurrences + 1,
            'message' => $this->trim($result->message),
            'last_result' => $this->snapshot($result),
            'severity' => $result->status,
            'max_severity' => Status::worst($incident->max_severity, $result->status),
        ])->save();

        if ($result->status === $previous) {
            return;
        }

        if ($result->status === Status::Error) {
            $this->event($incident, IncidentEventType::Escalated, $result->message, notify: true, at: $now);
            $this->feed($incident, 'incident.escalated', "{$incident->title} escalated to error: {$result->message}");
            $this->adoptChildren($incident, $now);
        } else {
            $this->event($incident, IncidentEventType::Deescalated, $result->message, notify: false, at: $now);
            $this->feed($incident, 'incident.deescalated', "{$incident->title} eased to warning: {$result->message}");
        }
    }

    public function acknowledge(Incident $incident, User $user): void
    {
        if (! $incident->isOpen() || $incident->isAcknowledged()) {
            return;
        }

        $incident->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $user->id])->save();

        $this->event($incident, IncidentEventType::Acknowledged, "{$user->name} acknowledged", notify: (bool) config('sextant.notifications.notify_acknowledgements'), userId: $user->id);
        $this->feed($incident, 'incident.acknowledged', "{$user->name} acknowledged {$incident->title}", userId: $user->id);
    }

    /**
     * A reminder (sweep): still failing after 4 hours, after a mute ended, or
     * after the parent server recovered (§6.3, §6.5, §6.6).
     */
    public function remind(Incident $incident, string $message, CarbonInterface $now): void
    {
        $incident->forceFill(['last_notified_at' => $now])->save();

        $this->event($incident, IncidentEventType::Reminder, $message, notify: true, at: $now);
    }

    public function resolve(Incident $incident, IncidentResolution $resolution, CarbonInterface $now, ?string $message = null): void
    {
        if (! $incident->isOpen()) {
            return;
        }

        $incident->forceFill(['resolved_at' => $now, 'resolution' => $resolution])->save();

        $minutes = (int) max(1, round($incident->opened_at->diffInMinutes($now, true)));
        $message ??= ucfirst($resolution->label())." after {$minutes} min";

        $this->event($incident, IncidentEventType::Resolved, $message, notify: true, at: $now);
        $this->feed($incident, 'incident.resolved', "{$incident->title} resolved: {$resolution->label()} after {$minutes} min");
    }

    /**
     * Resolve the open incidents of these monitors, e.g. when they're
     * disabled or deleted, or their resource is deleted (§6.4). Their state
     * goes back to Unknown with no streaks, so a monitor that's re-enabled
     * or restored starts afresh instead of showing the incident's severity.
     *
     * @param  iterable<Monitor>  $monitors
     */
    public function resolveFor(iterable $monitors, IncidentResolution $resolution): void
    {
        foreach ($monitors as $monitor) {
            $incident = Incident::query()->open()->where('monitor_id', $monitor->id)->first();

            if ($incident !== null) {
                $this->resolve($incident, $resolution, now());
            }

            MonitorState::query()->whereKey($monitor->id)->update([
                'status' => Status::Unknown,
                'status_changed_at' => now(),
                'consecutive_failures' => 0,
                'consecutive_successes' => 0,
                'consecutive_unknowns' => 0,
            ]);
        }
    }

    /** `"{resource} — {checker name}[ ({label})]"`, e.g. `web01 — TCP port (SSH)` (§3.11). */
    public function title(Monitor $monitor, Model&Monitorable $resource): string
    {
        return mb_strimwidth(
            "{$resource->resourceName()} — {$this->registry->name($monitor->checker)}".($monitor->label !== null ? " ({$monitor->label})" : ''),
            0,
            255,
            '…',
        );
    }

    /**
     * §6.5 adoption when an incident opens: a site joins its backend's
     * outage; a server outage gathers its sites' recent incidents.
     */
    private function group(Incident $incident, Model&Monitorable $resource, CarbonInterface $now): void
    {
        if ($resource instanceof Site && $resource->server_id !== null) {
            $parent = $this->outageOf($resource->server_id);

            if ($parent !== null) {
                $incident->forceFill(['parent_id' => $parent->id])->save();
            }
        }

        if ($resource instanceof Server) {
            $this->adoptChildren($incident, $now);
        }
    }

    /**
     * A server's error-severity `server.tcp` incident adopts open root
     * incidents of its sites that opened in the last 15 minutes.
     */
    private function adoptChildren(Incident $incident, CarbonInterface $now): void
    {
        if ($incident->checker !== 'server.tcp' || $incident->severity !== Status::Error || $incident->monitorable_type !== 'server') {
            return;
        }

        $parent = $this->outageOf($incident->monitorable_id);

        if ($parent === null) {
            return;
        }

        $window = (int) config('sextant.grouping.adopt_window_minutes');

        $orphans = Incident::query()
            ->open()
            ->roots()
            ->where('monitorable_type', 'site')
            ->whereIn('monitorable_id', Site::query()->where('server_id', $incident->monitorable_id)->select('id'))
            ->where('opened_at', '>=', $now->copy()->subMinutes($window))
            ->pluck('id');

        // Two steps: MySQL can't update a table it's reading in a subquery.
        Incident::query()->whereKey($orphans)->update(['parent_id' => $parent->id]);
    }

    /** The earliest open error-severity `server.tcp` incident on a server (§6.5). */
    private function outageOf(int $serverId): ?Incident
    {
        return Incident::query()
            ->open()
            ->where('monitorable_type', 'server')
            ->where('monitorable_id', $serverId)
            ->where('checker', 'server.tcp')
            ->where('severity', Status::Error)
            ->orderBy('opened_at')
            ->orderBy('id')
            ->first();
    }

    private function event(Incident $incident, IncidentEventType $type, string $message, bool $notify, ?int $userId = null, ?CarbonInterface $at = null): void
    {
        IncidentEvent::query()->create([
            'incident_id' => $incident->id,
            'type' => $type,
            'severity' => $incident->severity,
            'message' => $this->trim($message),
            'user_id' => $userId,
            'notify' => $notify,
            'created_at' => $at ?? now(),
        ]);
    }

    private function feed(Incident $incident, string $type, string $summary, ?int $userId = null): void
    {
        $resource = $incident->monitorable;

        if ($resource === null) {
            return;
        }

        ActivityRecorder::record(
            ActivityCategory::Status,
            $type,
            $resource,
            $summary,
            ['severity' => $incident->severity->value],
            userId: $userId,
            monitorId: $incident->monitor_id,
            incidentId: $incident->id,
        );
    }

    /** The server itself, or the site's backend at open time (§3.11). */
    private function serverIdOf(Model $resource): ?int
    {
        return match (true) {
            $resource instanceof Server => $resource->id,
            $resource instanceof Site => $resource->server_id,
            default => null,
        };
    }

    /**
     * @return array{status: string, message: string, data: array<string, mixed>}
     */
    private function snapshot(CheckResult $result): array
    {
        return ['status' => $result->status->value, 'message' => $result->message, 'data' => $result->data];
    }

    private function trim(string $message): string
    {
        return mb_strimwidth($message, 0, 1000, '…');
    }
}
