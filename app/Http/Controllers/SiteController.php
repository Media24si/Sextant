<?php

namespace App\Http\Controllers;

use App\Actions\Sites\AddSites;
use App\Data\ActivityItemData;
use App\Data\OptionData;
use App\Data\SiteData;
use App\Data\SiteFormData;
use App\Data\SiteRowData;
use App\Detection\InvalidUrl;
use App\Detection\SiteDetector;
use App\Enums\Status;
use App\Http\Requests\AddSiteRequest;
use App\Http\Requests\SiteRequest;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\MonitorProps;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Sites (SPEC §13.6). Add site detects the domain and serving path from a
 * URL (§10); the edit form changes them by hand.
 */
class SiteController extends Controller
{
    private const ACTIVITY_SHOWN = 20;

    private const FILTERS = ['show', 'front', 'backend', 'search'];

    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(Site::query()->with(['domain', 'frontService', 'server']), $request)
            ->allowedFilters(
                AllowedFilter::callback('show', function (Builder $query, mixed $value): void {
                    match ($value) {
                        'problems' => $query->whereIn('status', [Status::Warning, Status::Error]),
                        // "Needs review" = no backend assigned, e.g. an import that could only suggest one (§10.6).
                        'needs_review' => $query->whereNull('server_id'),
                        'deleted' => $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('sites.deleted_at'),
                        default => null,
                    };
                }),
                AllowedFilter::callback('front', function (Builder $query, mixed $value): void {
                    $value === 'direct'
                        ? $query->whereNull('front_service_id')
                        : $query->where('front_service_id', (int) $value);
                }),
                AllowedFilter::callback('backend', function (Builder $query, mixed $value): void {
                    $value === 'none'
                        ? $query->whereNull('server_id')
                        : $query->where('server_id', (int) $value);
                }),
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $term = '%'.(is_array($value) ? implode(',', $value) : (string) $value).'%';
                    $query->where(fn (Builder $query) => $query->where('hostname', 'like', $term)->orWhere('description', 'like', $term));
                }),
            )
            ->allowedSorts('hostname')
            ->defaultSort('hostname');

        return Inertia::render('sites/Index', [
            ...TableQuery::props($request, $query, SiteRowData::class, self::FILTERS, 'hostname'),
            'counts' => [
                'all' => Site::query()->count(),
                'problems' => Site::query()->whereIn('status', [Status::Warning, Status::Error])->count(),
                'needs_review' => Site::query()->whereNull('server_id')->count(),
                'deleted' => Site::onlyTrashed()->count(),
            ],
            'front_services' => $this->frontServiceOptions(),
            'servers' => $this->serverOptions(),
        ]);
    }

    public function show(Site $site): Response
    {
        $site->load(['domain' => fn ($query) => $query->withTrashed(), 'frontService', 'server']);

        return Inertia::render('sites/Show', [
            ...MonitorProps::for($site),
            'site' => SiteData::fromModel($site),
            'activity' => Activity::query()
                ->where('subject_type', 'site')
                ->where('subject_id', $site->id)
                ->with('user')
                ->latest('id')
                ->limit(self::ACTIVITY_SHOWN)
                ->get()
                ->map(ActivityItemData::fromModel(...)),
            'front_services' => $this->frontServiceOptions(),
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * Add site (SPEC §10.1, §13.6). `?url=` runs detection on load, e.g. back
     * from Add server; `?domain=` starts the URL with that domain.
     */
    public function create(Request $request): Response
    {
        $domain = Domain::query()->find($request->integer('domain'));
        $url = $request->query('url');

        return Inertia::render('sites/Add', [
            'url' => is_string($url) ? $url : null,
            'domain' => $domain?->display_name,
            'front_services' => $this->frontServiceOptions(),
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * Runs detection and returns what it found; writes nothing (SPEC §10.1).
     */
    public function detect(Request $request, SiteDetector $detector): JsonResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:2048']]);

        try {
            return response()->json($detector->detect((string) $request->input('url')));
        } catch (InvalidUrl $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
    }

    /**
     * Detection again for an existing site, which doesn't count as its own
     * duplicate (SPEC §10.7). JSON; writes nothing.
     */
    public function detectAgain(Site $site, SiteDetector $detector): JsonResponse
    {
        return response()->json($detector->detect($site->url, ignore: $site));
    }

    /**
     * Applies the serving path the admin confirmed after re-running detection (SPEC §10.7).
     *
     * SPEC: §13.3 lists only `detect-again`; applying needs a route of its own
     * because `PUT /sites/{site}` wants the whole form.
     */
    public function updatePath(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'front_service_id' => ['nullable', 'integer', Rule::exists('external_services', 'id')->whereNull('deleted_at')],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->whereNull('deleted_at')],
        ]);

        $site->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Serving path of {$site->display_hostname} saved."]);

        return to_route('sites.show', $site);
    }

    /**
     * Saves the reviewed site, its domain if new, and the `www.` sibling if asked (SPEC §10.4).
     */
    public function store(AddSiteRequest $request, AddSites $addSites): RedirectResponse
    {
        /** @var array{hostname: string, scheme: string, port: int|null, path: string, front_service_id?: int|null, server_id?: int|null, description?: string|null, github_url?: string|null, expected_target?: string|null, add_www?: bool} $input */
        $input = $request->validated();
        $site = [
            'hostname' => $input['hostname'],
            'scheme' => $input['scheme'],
            'port' => $input['port'],
            'path' => $input['path'],
            'front_service_id' => $input['front_service_id'] ?? null,
            'server_id' => $input['server_id'] ?? null,
        ];
        $sites = [[
            ...$site,
            'description' => $input['description'] ?? null,
            'github_url' => $input['github_url'] ?? null,
            'expected_target' => $input['expected_target'] ?? null,
        ]];

        if ($request->boolean('add_www')) {
            $sites[] = [...$site, 'hostname' => 'www.'.$input['hostname']];
        }

        $site = $addSites->handle((string) $request->registrableDomain(), $sites)[0];

        Inertia::flash('toast', ['type' => 'success', 'message' => count($sites) === 1
            ? "Site {$site->display_hostname} added."
            : "Sites {$site->display_hostname} and www.{$site->display_hostname} added."]);

        return to_route('sites.show', $site);
    }

    public function edit(Site $site): Response
    {
        return $this->form(SiteFormData::fromModel($site));
    }

    public function update(SiteRequest $request, Site $site): RedirectResponse
    {
        $site->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Site {$site->display_hostname} saved."]);

        return to_route('sites.show', $site);
    }

    public function destroy(Site $site): RedirectResponse
    {
        $site->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Site {$site->display_hostname} deleted."]);

        return to_route('domains.show', $site->domain_id);
    }

    public function restore(Site $site): RedirectResponse
    {
        $conflict = match (true) {
            Site::query()->where('hostname', $site->hostname)->exists() => 'it was added again',
            $site->domain()->doesntExist() => 'its domain was deleted; restore the domain first',
            default => null,
        };

        if ($conflict !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Can't restore {$site->display_hostname}: {$conflict}."]);

            return back();
        }

        $site->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Site {$site->display_hostname} restored."]);

        return to_route('sites.show', $site);
    }

    private function form(SiteFormData $form): Response
    {
        return Inertia::render('sites/Form', [
            'form' => $form,
            'domains' => Domain::query()->orderBy('name')->get()
                ->map(fn (Domain $domain): OptionData => new OptionData($domain->id, $domain->display_name)),
            'front_services' => $this->frontServiceOptions(),
            'servers' => $this->serverOptions(),
        ]);
    }

    /**
     * @return list<OptionData>
     */
    private function frontServiceOptions(): array
    {
        return array_values(ExternalService::query()->orderBy('name')->get()
            ->map(fn (ExternalService $service): OptionData => new OptionData($service->id, $service->name))->all());
    }

    /**
     * @return list<OptionData>
     */
    private function serverOptions(): array
    {
        return array_values(Server::query()->orderBy('name')->get()
            ->map(fn (Server $server): OptionData => new OptionData($server->id, $server->name))->all());
    }
}
