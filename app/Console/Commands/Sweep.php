<?php

namespace App\Console\Commands;

use App\Enums\IncidentEventType;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorState;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\EffectiveStatus;
use App\Monitoring\IncidentManager;
use App\Monitoring\ResourceStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute (SPEC §14): stale monitors become Unknown, resource statuses
 * are refreshed, and reminders go out for incidents that are still failing
 * after 4 hours, after a mute ended, or after their parent server recovered
 * (§6.3, §6.5, §6.6).
 */
#[Signature('sextant:sweep')]
#[Description('Mark stale monitors Unknown, refresh statuses and send reminders')]
class Sweep extends Command
{
    private const LAST_SWEEP_KEY = 'sextant:sweep:last';

    public function handle(IncidentManager $incidents): int
    {
        $now = CarbonImmutable::now();
        $lastSweep = CarbonImmutable::parse((string) Cache::get(self::LAST_SWEEP_KEY, $now->subMinutes(2)->toIso8601String()));
        Cache::forever(self::LAST_SWEEP_KEY, $now->toIso8601String());

        $stale = $this->staleStates($now);

        foreach ([Server::class, Domain::class, Site::class] as $model) {
            $model::query()->eachById(fn (Server|Domain|Site $resource): mixed => ResourceStatus::refresh($resource));
        }

        $reminders = $this->longRunning($incidents, $now)
            + $this->muteEnded($incidents, $lastSweep, $now)
            + $this->detachedChildren($incidents, $now);

        $this->components->info("{$stale} monitors went stale, {$reminders} reminders.");

        return self::SUCCESS;
    }

    private function staleStates(CarbonInterface $now): int
    {
        $stale = 0;

        MonitorState::query()
            ->where('status', '!=', Status::Unknown)
            ->where('stale_at', '<', $now)
            ->eachById(function (MonitorState $state) use ($now, &$stale): void {
                $severity = Incident::query()->open()->where('monitor_id', $state->monitor_id)->first()?->severity;
                $status = EffectiveStatus::of($state, $now, $severity);

                if ($status !== $state->status) {
                    $state->forceFill(['status' => $status, 'status_changed_at' => $now])->save();
                    $stale++;
                }
            }, column: 'monitor_id');

        return $stale;
    }

    /**
     * Open, unacknowledged, unmuted, error-severity root incidents not
     * notified for `reminder_hours` (§6.3).
     */
    private function longRunning(IncidentManager $incidents, CarbonInterface $now): int
    {
        $before = $now->copy()->subHours((int) config('sextant.notifications.reminder_hours'));
        $count = 0;

        Incident::query()
            ->open()
            ->roots()
            ->whereNull('acknowledged_at')
            ->where('severity', Status::Error)
            ->whereRaw('COALESCE(last_notified_at, opened_at) <= ?', [$before])
            ->whereHas('monitor', fn ($monitor) => $monitor->where(fn ($query) => $query->whereNull('muted_until')->orWhere('muted_until', '<=', $now)))
            // By id: a reminder moves `last_notified_at` out of the filter, which
            // would make offset chunks skip incidents.
            ->eachById(function (Incident $incident) use ($incidents, $now, &$count): void {
                $incidents->remind($incident, 'Still failing after '.$this->duration($incident->opened_at, $now), $now);
                $count++;
            });

        return $count;
    }

    /** A mute ended since the last sweep while an incident is open (§6.6). */
    private function muteEnded(IncidentManager $incidents, CarbonInterface $lastSweep, CarbonInterface $now): int
    {
        $count = 0;

        Monitor::query()
            ->where('muted_until', '>', $lastSweep)
            ->where('muted_until', '<=', $now)
            ->whereHas('openIncident')
            ->with('openIncident')
            ->each(function (Monitor $monitor) use ($incidents, $now, &$count): void {
                if ($monitor->openIncident !== null) {
                    $incidents->remind($monitor->openIncident, 'Still failing after the mute ended', $now);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * A child still open `detach_after_minutes` after its parent resolved
     * gets one reminder; it keeps its parent (§6.5).
     */
    private function detachedChildren(IncidentManager $incidents, CarbonInterface $now): int
    {
        $count = 0;
        $before = $now->copy()->subMinutes((int) config('sextant.grouping.detach_after_minutes'));

        Incident::query()
            ->open()
            ->whereNotNull('parent_id')
            ->whereHas('parent', fn ($parent) => $parent->whereNotNull('resolved_at')->where('resolved_at', '<=', $before))
            ->with(['parent.monitorable'])
            ->each(function (Incident $child) use ($incidents, $now, &$count): void {
                $parent = $child->parent;

                if ($parent === null || $parent->resolved_at === null) {
                    return;
                }

                $reminded = $child->events()
                    ->where('type', IncidentEventType::Reminder)
                    ->where('created_at', '>=', $parent->resolved_at)
                    ->exists();

                if (! $reminded) {
                    $server = $parent->monitorable?->resourceName() ?? 'the server';
                    $incidents->remind($child, "Still failing after {$server} recovered", $now);
                    $count++;
                }
            });

        return $count;
    }

    /** "4 h", "1 h 30 min", "25 min". */
    private function duration(CarbonInterface $from, CarbonInterface $to): string
    {
        $minutes = (int) $from->diffInMinutes($to, true);
        $hours = intdiv($minutes, 60);

        return match (true) {
            $hours === 0 => "{$minutes} min",
            $minutes % 60 === 0 => "{$hours} h",
            default => "{$hours} h ".($minutes % 60).' min',
        };
    }
}
