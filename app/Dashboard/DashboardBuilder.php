<?php

namespace App\Dashboard;

use App\Data\ActivityItemData;
use App\Data\Dashboard\ExpiringItemData;
use App\Data\Dashboard\IncidentItemData;
use App\Data\Dashboard\IssueGroupData;
use App\Data\Dashboard\ResolvedItemData;
use App\Data\Dashboard\ResourceRefData;
use App\Data\Dashboard\SoftwareMinimumRowData;
use App\Data\Dashboard\StatusCountsData;
use App\Data\Dashboard\SystemHealthData;
use App\Data\Dashboard\TotalsData;
use App\Enums\Software;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Concerns\Monitorable;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MinimumVersion;
use App\Models\Monitor;
use App\Models\MonitorState;
use App\Models\Server;
use App\Models\ServerSoftware;
use App\Models\Site;
use App\Monitoring\MonitorClaimer;
use App\Monitoring\NetworkHealth;
use App\Versions\CollectorKit;
use App\Versions\MinimumVersions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the dashboard props (SPEC §13.7) from materialised statuses with a
 * handful of queries. Each prop has its own method so polling can reload
 * just those (`usePoll(..., { only: [...] })`).
 *
 * SPEC: §13.7 calls this `DashboardData`; that name is the laravel-data class
 * describing the props (`App\Data\Dashboard\DashboardData`).
 */
class DashboardBuilder
{
    private const RESOLVED_HOURS = 24;

    private const RESOLVED_SHOWN = 20;

    private const EXPIRING_DAYS = 60;

    private const CHANGES_SHOWN = 50;

    /** Without a dispatch for this long, checks count as stopped (SPEC §13.7). */
    private const DISPATCH_GRACE_SECONDS = 180;

    public function __construct(
        private NetworkHealth $network,
        private MinimumVersions $minimums,
        private CollectorKit $collector,
    ) {}

    /**
     * Each software (and PHP branch) seen on servers or with a minimum, the
     * minimum, and the servers below it (§13.7).
     *
     * @return list<SoftwareMinimumRowData>
     */
    public function software(): array
    {
        $readings = ServerSoftware::query()->where('software', '!=', Software::Os)->whereHas('server')->with('server')->get();
        $rows = [];

        foreach ($readings as $reading) {
            $rows["{$reading->software->value}:{$reading->branch}"] ??= ['software' => $reading->software, 'branch' => $reading->branch];
        }

        foreach (MinimumVersion::query()->get() as $minimum) {
            if ($readings->doesntContain('software', $minimum->software)) {
                $rows["{$minimum->software->value}:"] = ['software' => $minimum->software, 'branch' => ''];
            }
        }

        $order = array_flip(array_map(fn (Software $software): string => $software->value, Software::cases()));
        uasort($rows, fn (array $a, array $b): int => [$order[$a['software']->value], $a['branch']] <=> [$order[$b['software']->value], $b['branch']]);

        return array_values(array_map(function (array $row) use ($readings): SoftwareMinimumRowData {
            /** @var Software $software */
            $software = $row['software'];
            $minimum = $this->minimums->for($software);
            $below = $readings
                ->filter(fn (ServerSoftware $reading): bool => $reading->software === $software && $reading->branch === $row['branch'] && $this->minimums->isBelow($reading))
                ->map(fn (ServerSoftware $reading): array => ['server' => $reading->server->name, 'version' => $reading->version])
                ->values()
                ->all();

            // A branch row shows the version it's compared with; a row without
            // a branch shows every listed version.
            $minVersion = $row['branch'] === '' ? $minimum?->versionsList() : $minimum?->floorFor($row['branch']);

            return new SoftwareMinimumRowData($software->labelWithBranch($row['branch']), $row['branch'], $minVersion, array_values($below));
        }, $rows));
    }

    public function totals(): TotalsData
    {
        return new TotalsData(
            servers: $this->counts(Server::query()),
            domains: $this->counts(Domain::query()),
            sites: $this->counts(Site::query()),
            sites_unassigned: Site::query()->whereNull('server_id')->count(),
            servers_below_minimum: count($this->minimums->serverIdsBelow()),
            servers_collector_outdated: Server::query()->collectorOutdated($this->collector->version())->count(),
        );
    }

    /**
     * Open root incidents of unmuted monitors, grouped by resource, worst
     * first, then oldest first (§13.7).
     *
     * @return list<IssueGroupData>
     */
    public function issues(): array
    {
        return $this->groups(muted: false);
    }

    /**
     * The same for muted monitors; shown collapsed (§6.6).
     *
     * @return list<IssueGroupData>
     */
    public function muted(): array
    {
        return $this->groups(muted: true);
    }

    /** Monitors whose latest run failed but haven't opened an incident (§6.1). */
    public function pendingCount(): int
    {
        return Monitor::query()
            ->where('enabled', true)
            ->whereHasMorph('monitorable', '*')
            ->whereHas('state', fn (Builder $state) => $state->whereIn('last_result_status', [Status::Warning, Status::Error]))
            ->whereDoesntHave('openIncident')
            ->count();
    }

    /**
     * Root incidents resolved in the last 24 hours, newest first, at most 20.
     *
     * @return list<ResolvedItemData>
     */
    public function resolved(): array
    {
        return array_values(Incident::query()
            ->resolved()
            ->whereNull('parent_id')
            ->where('resolved_at', '>=', now()->subHours(self::RESOLVED_HOURS))
            ->withCount('children')
            ->latest('resolved_at')
            ->limit(self::RESOLVED_SHOWN)
            ->get()
            ->map(fn (Incident $incident): ResolvedItemData => new ResolvedItemData(
                id: $incident->id,
                title: $incident->title,
                opened_at: $incident->opened_at->toIso8601String(),
                resolved_at: (string) $incident->resolved_at?->toIso8601String(),
                children_count: (int) $incident->getAttribute('children_count'),
                url: route('incidents.show', $incident),
            ))
            ->all());
    }

    /**
     * Certificates and domains expiring within 60 days, soonest first.
     *
     * @return list<ExpiringItemData>
     */
    public function expiring(): array
    {
        $timezone = (string) config('sextant.display_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $items = [];

        foreach (Domain::query()->whereNotNull('expires_at')->where('expires_at', '<=', $today->addDays(self::EXPIRING_DAYS))->get() as $domain) {
            $expires = CarbonImmutable::parse($domain->expires_at?->toDateString(), $timezone);
            $items[] = new ExpiringItemData('domain', $domain->display_name, $expires->toDateString(), (int) $today->diffInDays($expires, false), route('domains.show', $domain));
        }

        $certificates = Monitor::query()
            ->where('checker', 'site.ssl')
            ->where('enabled', true)
            ->where('monitorable_type', 'site')
            ->whereHasMorph('monitorable', '*')
            ->with(['state', 'monitorable'])
            ->get();

        foreach ($certificates as $monitor) {
            $expiresAt = $monitor->state?->data['expires_at'] ?? null;
            $site = $monitor->monitorable;

            if (! is_string($expiresAt) || ! $site instanceof Site) {
                continue;
            }

            $expires = CarbonImmutable::parse($expiresAt)->setTimezone($timezone)->startOfDay();
            $days = (int) $today->diffInDays($expires, false);

            if ($days <= self::EXPIRING_DAYS) {
                $items[] = new ExpiringItemData('certificate', $site->display_hostname, $expires->toDateString(), $days, route('sites.show', $site));
            }
        }

        usort($items, fn (ExpiringItemData $a, ExpiringItemData $b): int => [$a->days, $a->name] <=> [$b->days, $b->name]);

        return $items;
    }

    /**
     * @return list<ActivityItemData>
     */
    public function changes(): array
    {
        return array_values(Activity::query()
            ->with('user')
            ->latest('id')
            ->limit(self::CHANGES_SHOWN)
            ->get()
            ->map(ActivityItemData::fromModel(...))
            ->all());
    }

    public function system(): SystemHealthData
    {
        $lastDispatch = Cache::get(MonitorClaimer::LAST_DISPATCH_KEY);

        $lastDispatch = is_string($lastDispatch) ? $lastDispatch : null;

        return new SystemHealthData(
            last_dispatch_at: $lastDispatch,
            checks_running: $lastDispatch !== null
                && CarbonImmutable::parse($lastDispatch)->gte(now()->subSeconds(self::DISPATCH_GRACE_SECONDS)),
            stale_monitors: MonitorState::query()
                ->where('stale_at', '<', now())
                ->whereHas('monitor', fn (Builder $monitor) => $monitor->where('enabled', true)->whereHasMorph('monitorable', '*'))
                ->count(),
            network_healthy: $this->network->lastKnown(),
        );
    }

    /**
     * @return list<IssueGroupData>
     */
    private function groups(bool $muted): array
    {
        $roots = Incident::query()
            ->open()
            ->roots()
            ->whereHas('monitor', fn (Builder $monitor) => $muted
                ? $monitor->where('muted_until', '>', now())
                : $monitor->where(fn (Builder $query) => $query->whereNull('muted_until')->orWhere('muted_until', '<=', now())))
            ->with([
                'monitorable',
                'acknowledgedBy',
                'monitor.state',
                'children' => fn ($children) => $children->open()->with(['monitorable', 'acknowledgedBy', 'monitor.state'])->orderBy('opened_at'),
            ])
            ->get();

        return array_values($roots
            ->groupBy(fn (Incident $incident): string => "{$incident->monitorable_type}:{$incident->monitorable_id}")
            ->map(function (Collection $incidents): ?IssueGroupData {
                /** @var Incident $first */
                $first = $incidents->first();
                $resource = $first->monitorable;

                if ($resource === null) {
                    return null;
                }

                $sorted = $incidents->sortBy([
                    fn (Incident $a, Incident $b): int => $b->severity->rank() <=> $a->severity->rank(),
                    fn (Incident $a, Incident $b): int => $a->opened_at <=> $b->opened_at,
                ])->values();

                return new IssueGroupData(
                    resource: $this->ref($resource),
                    incidents: array_values($sorted->map(fn (Incident $incident): IncidentItemData => $this->item($incident))->all()),
                    children: array_values($sorted->flatMap(fn (Incident $incident): Collection => $incident->children)
                        ->map(fn (Incident $child): IncidentItemData => $this->item($child))
                        ->all()),
                );
            })
            ->filter()
            ->sortBy([
                fn (IssueGroupData $a, IssueGroupData $b): int => $this->worst($b)->rank() <=> $this->worst($a)->rank(),
                fn (IssueGroupData $a, IssueGroupData $b): int => $this->oldest($a) <=> $this->oldest($b),
            ])
            ->all());
    }

    private function item(Incident $incident): IncidentItemData
    {
        $resource = $incident->monitorable;

        return new IncidentItemData(
            id: $incident->id,
            title: $incident->title,
            message: $incident->message,
            severity: $incident->severity,
            opened_at: $incident->opened_at->toIso8601String(),
            acknowledged_by: $incident->acknowledgedBy?->name,
            recovering: ($incident->monitor->state->consecutive_successes ?? 0) > 0,
            serving_path: $resource instanceof Site ? $this->servingPath($resource) : null,
            url: route('incidents.show', $incident),
        );
    }

    private function ref(Model&Monitorable $resource): ResourceRefData
    {
        $type = $resource->getMorphClass();
        /** @var Status $status */
        $status = $resource->getAttribute('status');

        return new ResourceRefData($type, (int) $resource->getKey(), $resource->resourceName(), $status, route("{$type}s.show", $resource->getKey()));
    }

    private function servingPath(Site $site): string
    {
        $path = $site->loadMissing(['frontService', 'server'])->servingPath();

        return ($path->front !== null ? "{$path->front} → " : '').($path->backend ?? 'backend not assigned');
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function counts(Builder $query): StatusCountsData
    {
        /** @var array<string, int> $counts */
        $counts = $query->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $count): int => (int) $count)->all();

        return new StatusCountsData(
            total: array_sum($counts),
            ok: $counts[Status::Ok->value] ?? 0,
            warning: $counts[Status::Warning->value] ?? 0,
            error: $counts[Status::Error->value] ?? 0,
            unknown: $counts[Status::Unknown->value] ?? 0,
        );
    }

    private function worst(IssueGroupData $group): Status
    {
        return Status::worst(...array_map(fn (IncidentItemData $item): Status => $item->severity, $group->incidents));
    }

    private function oldest(IssueGroupData $group): string
    {
        $openedAt = array_map(fn (IncidentItemData $item): string => $item->opened_at, $group->incidents);

        return $openedAt === [] ? '' : min($openedAt);
    }
}
