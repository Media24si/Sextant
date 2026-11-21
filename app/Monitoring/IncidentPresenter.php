<?php

namespace App\Monitoring;

use App\Data\Incidents\IncidentDetailData;
use App\Data\Incidents\IncidentEventData;
use App\Data\Incidents\IncidentResultData;
use App\Data\Incidents\IncidentRowData;
use App\Data\Monitors\MonitorResourceData;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Turns incidents into the data the Incidents UI shows (SPEC §13.6). */
class IncidentPresenter
{
    /** What lists eager-load for `row()`. */
    public const WITH = ['monitorable', 'acknowledgedBy', 'monitor.state'];

    public function __construct(private MonitorPresenter $monitors) {}

    public function row(Incident $incident): IncidentRowData
    {
        /** @var (Model&Monitorable)|null $resource */
        $resource = $incident->monitorable;
        $state = $incident->monitor->state;

        return new IncidentRowData(
            id: $incident->id,
            title: $incident->title,
            severity: $incident->severity,
            state: match (true) {
                ! $incident->isOpen() => 'resolved',
                $incident->isAcknowledged() => 'acknowledged',
                default => 'open',
            },
            message: $incident->message,
            resource: new MonitorResourceData($incident->monitorable_type, $incident->monitorable_id, $resource?->resourceName() ?? '(deleted)'),
            checker: $incident->checker,
            opened_at: $incident->opened_at->toIso8601String(),
            resolved_at: $incident->resolved_at?->toIso8601String(),
            resolution: $incident->resolution?->label(),
            acknowledged_by: $incident->acknowledgedBy?->name,
            parent_id: $incident->parent_id,
            children_count: (int) ($incident->getAttribute('children_count') ?? $incident->children()->count()),
            recovering: $incident->isOpen() && ($state->consecutive_successes ?? 0) > 0,
            muted: $incident->monitor->isMuted(),
        );
    }

    public function detail(Incident $incident): IncidentDetailData
    {
        $incident->load([...self::WITH, 'events.user', 'parent', 'children' => fn ($query) => $query->with(self::WITH)->withCount('children')->orderBy('opened_at')]);
        $incident->loadCount('children');
        $resource = $incident->monitorable;

        return new IncidentDetailData(
            incident: $this->row($incident),
            max_severity: $incident->max_severity,
            occurrences: $incident->occurrences,
            last_seen_at: $incident->last_seen_at->toIso8601String(),
            acknowledged_at: $incident->acknowledged_at?->toIso8601String(),
            monitor_id: $incident->monitor_id,
            monitor_name: $this->monitors->name($incident->monitor),
            monitor_deleted: $incident->monitor->trashed(),
            serving_path: $resource instanceof Site ? $resource->loadMissing(['frontService', 'server'])->servingPath() : null,
            first_result: $this->result($incident->first_result),
            last_result: $this->result($incident->last_result),
            events: array_values($incident->events->sortBy('id')->map(fn (IncidentEvent $event): IncidentEventData => new IncidentEventData(
                id: $event->id,
                type: $event->type->value,
                severity: $event->severity,
                message: $event->message,
                user: $event->user?->name,
                notify: $event->notify,
                notified_at: $event->notified_at?->toIso8601String(),
                suppressed_reason: $event->suppressed_reason,
                created_at: $event->created_at->toIso8601String(),
            ))->all()),
            parent: $incident->parent === null ? null : $this->row($incident->parent->load(self::WITH)),
            children: array_values($incident->children->map(fn (Incident $child): IncidentRowData => $this->row($child))->all()),
        );
    }

    /**
     * The latest incidents of a resource's page. A server's page also shows
     * its sites' incidents through the `server_id` snapshot (§3.11).
     *
     * @return list<IncidentRowData>
     */
    public function latestFor(Model&Monitorable $resource, int $limit = 10): array
    {
        return array_values(Incident::query()
            ->when(
                $resource->getMorphClass() === 'server',
                fn (Builder $query) => $query->where('server_id', $resource->getKey()),
                fn (Builder $query) => $query->where('monitorable_type', $resource->getMorphClass())->where('monitorable_id', $resource->getKey()),
            )
            ->with(self::WITH)
            ->withCount('children')
            ->orderByRaw('resolved_at IS NULL DESC')
            ->latest('opened_at')
            ->limit($limit)
            ->get()
            ->map(fn (Incident $incident): IncidentRowData => $this->row($incident))
            ->all());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function result(array $result): IncidentResultData
    {
        return new IncidentResultData(
            Status::tryFrom((string) ($result['status'] ?? '')) ?? Status::Unknown,
            (string) ($result['message'] ?? ''),
            (array) ($result['data'] ?? []),
        );
    }
}
