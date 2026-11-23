<?php

namespace App\Http\Controllers;

use App\Actions\Servers\SaveServer;
use App\Data\ActivityItemData;
use App\Data\ChoiceData;
use App\Data\ServedSiteData;
use App\Data\ServerData;
use App\Data\ServerFormData;
use App\Data\ServerRowData;
use App\Data\ServerVersionData;
use App\Data\ServerVersionsPanelData;
use App\Data\TrafficGroupData;
use App\Enums\ChoiceList;
use App\Enums\Status;
use App\Http\Requests\ServerRequest;
use App\Jobs\AttachServerMonitors;
use App\Models\Activity;
use App\Models\Choice;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Monitoring\IncidentPresenter;
use App\Monitoring\MonitorProps;
use App\Support\BlastRadius;
use App\Support\ReturnTo;
use App\Support\TableQuery;
use App\Versions\CollectorKit;
use App\Versions\MinimumVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ServerController extends Controller
{
    private const SITES_SHOWN = 10;

    private const ACTIVITY_SHOWN = 20;

    /**
     * Servers list (SPEC §13.6). Version columns arrive in Phase 7.
     */
    public function index(Request $request): Response
    {
        $below = app(MinimumVersions::class)->serverIdsBelow();
        $latest = app(CollectorKit::class)->version();
        $query = QueryBuilder::for(Server::query()->with(['primaryAddress', 'os', 'backupLocations', 'software'])->withCount('sites'), $request)
            ->allowedFilters(
                AllowedFilter::callback('show', function (Builder $query, mixed $value) use ($below, $latest): void {
                    match ($value) {
                        'problems' => $query->whereIn('status', [Status::Warning, Status::Error]),
                        'below_minimum' => $query->whereKey($below),
                        'no_backup' => $query->doesntHave('backupLocations'),
                        'collector_outdated' => $query->whereIn('servers.id', Server::query()->collectorOutdated($latest)->select('servers.id')),
                        'deleted' => $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('servers.deleted_at'),
                        default => null,
                    };
                }),
            )
            ->allowedSorts('name', 'sites_count')
            ->defaultSort('name');

        return Inertia::render('servers/Index', [
            ...TableQuery::props($request, $query, ServerRowData::class, ['show'], 'name'),
            'counts' => [
                'all' => Server::query()->count(),
                'problems' => Server::query()->whereIn('status', [Status::Warning, Status::Error])->count(),
                'below_minimum' => count($below),
                'no_backup' => Server::query()->doesntHave('backupLocations')->count(),
                'collector_outdated' => Server::query()->collectorOutdated($latest)->count(),
                'deleted' => Server::onlyTrashed()->count(),
            ],
        ]);
    }

    /**
     * Server page (SPEC §13.6): header, how traffic reaches it, sites served
     * and changes. Monitors, versions and incidents join in later phases.
     */
    public function show(Server $server): Response
    {
        $server->load(['addresses', 'os', 'backupLocations']);

        $sites = $server->sites()->with(['frontService', 'server', 'application'])->orderBy('hostname')->get();

        return Inertia::render('servers/Show', [
            ...MonitorProps::for($server),
            'versions' => $this->versionsPanel($server),
            'incidents' => app(IncidentPresenter::class)->latestFor($server),
            'server' => ServerData::fromModel($server),
            'traffic' => $this->traffic(array_values($sites->all())),
            'sites' => $sites->take(self::SITES_SHOWN)->map(ServedSiteData::fromModel(...))->values(),
            'sites_total' => $sites->count(),
            'applications_total' => $sites->pluck('application_id')->filter()->unique()->count(),
            'radius' => BlastRadius::of($sites),
            'activity' => Activity::query()
                ->where('server_id', $server->id)
                ->with('user')
                ->latest('id')
                ->limit(self::ACTIVITY_SHOWN)
                ->get()
                ->map(ActivityItemData::fromModel(...)),
        ]);
    }

    public function create(Request $request): Response
    {
        $ip = $request->query('ip');

        return $this->form(ServerFormData::blank(is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : ''), ReturnTo::from($request));
    }

    public function store(ServerRequest $request, SaveServer $saveServer): RedirectResponse
    {
        /** @var array{name: string, hostname?: string|null, provider?: string|null, os_choice_id?: int|null, notes?: string|null, versions_url?: string|null, addresses: list<array{ip: string, is_primary: bool}>, backup_location_ids?: list<int>} $input */
        $input = $request->validated();
        $server = $saveServer->handle(new Server, $input);
        AttachServerMonitors::dispatch($server);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Server {$server->name} added."]);
        $returnTo = ReturnTo::from($request);

        return $returnTo !== null ? redirect($returnTo) : to_route('servers.show', $server);
    }

    public function edit(Server $server): Response
    {
        $server->load(['addresses', 'backupLocations']);

        return $this->form(ServerFormData::fromModel($server));
    }

    public function update(ServerRequest $request, Server $server, SaveServer $saveServer): RedirectResponse
    {
        /** @var array{name: string, hostname?: string|null, provider?: string|null, os_choice_id?: int|null, notes?: string|null, versions_url?: string|null, addresses: list<array{ip: string, is_primary: bool}>, backup_location_ids?: list<int>} $input */
        $input = $request->validated();
        $saveServer->handle($server, $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Server {$server->name} saved."]);

        return to_route('servers.show', $server);
    }

    /**
     * SPEC: deleting a server that is still the backend of live sites isn't
     * defined (TODO "Spec issues found"). Like domains and external services,
     * it's refused until those sites move elsewhere.
     */
    public function destroy(Server $server): RedirectResponse
    {
        $sites = $server->sites()->count();

        if ($sites > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$server->name} is the backend of {$sites} ".str('site')->plural($sites).'. Move them to another server first.']);

            return back();
        }

        $server->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Server {$server->name} deleted."]);

        return to_route('servers.index');
    }

    public function restore(Server $server): RedirectResponse
    {
        $conflict = match (true) {
            Server::query()->where('name', $server->name)->exists() => "another server is already called {$server->name}",
            ServerAddress::query()->whereIn('ip', $server->addresses()->pluck('ip'))->whereHas('server')->exists() => 'one of its addresses now belongs to another server',
            default => null,
        };

        if ($conflict !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Can't restore {$server->name}: {$conflict}."]);

            return back();
        }

        $server->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Server {$server->name} restored."]);

        return to_route('servers.show', $server);
    }

    private function versionsPanel(Server $server): ServerVersionsPanelData
    {
        $rows = $server->software()->get();
        $kit = app(CollectorKit::class);
        $monitor = $server->monitors()->where('checker', 'server.versions')->with('state')->first();

        return new ServerVersionsPanelData(
            configured: $server->versions_url !== null && $server->versions_token !== null,
            endpoint_url: $server->versions_url,
            token_masked: ServerVersionsController::maskedToken($server),
            read_at: $rows->max('read_at')?->toIso8601String(),
            fetched_at: $rows->max('fetched_at')?->toIso8601String(),
            os_reported: $server->os_reported,
            os_set: $server->os?->label,
            last_message: $monitor?->state?->message,
            versions: array_values($rows->map(ServerVersionData::fromModel(...))->all()),
            collector_version: $server->collector_version,
            collector_latest: $kit->version(),
            collector_outdated: $kit->isOutdated($server, $rows->isNotEmpty()),
        );
    }

    private function form(ServerFormData $form, ?string $returnTo = null): Response
    {
        return Inertia::render('servers/Form', [
            'form' => $form,
            'return_to' => $returnTo,
            'versions_default_path' => (string) config('sextant.versions.default_path'),
            'versions_endpoint_file' => (string) config('sextant.versions.endpoint_file'),
            'os_choices' => Choice::ofList(ChoiceList::Os)->get()->map(ChoiceData::fromModel(...)),
            'backup_choices' => Choice::ofList(ChoiceList::BackupLocation)->get()->map(ChoiceData::fromModel(...)),
        ]);
    }

    /**
     * Sites grouped by the external service in front of them, then the ones
     * whose DNS points straight at the server.
     *
     * @param  list<Site>  $sites
     * @return list<TrafficGroupData>
     */
    private function traffic(array $sites): array
    {
        $groups = collect($sites)
            ->groupBy(fn (Site $site): int => $site->front_service_id ?? 0)
            ->map(function ($group, int $frontId): TrafficGroupData {
                /** @var Site $first */
                $first = $group->first();

                return new TrafficGroupData(
                    name: $frontId === 0 ? 'Direct' : (string) $first->frontService?->name,
                    kind: $frontId === 0 ? null : $first->frontService?->kind,
                    sites_count: $group->count(),
                    first_site: $first->display_hostname,
                );
            });

        return array_values($groups->sortBy(fn (TrafficGroupData $group): string => ($group->kind === null ? '1' : '0').$group->name)->all());
    }
}
