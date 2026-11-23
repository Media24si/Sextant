<?php

namespace App\Http\Controllers;

use App\Data\BackendGroupData;
use App\Data\ExternalServiceData;
use App\Data\ExternalServiceFormData;
use App\Data\ExternalServiceRowData;
use App\Data\ServedSiteData;
use App\Http\Requests\ExternalServiceRequest;
use App\Models\ExternalService;
use App\Models\Site;
use App\Support\BlastRadius;
use App\Support\ReturnTo;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Services in front of our servers that we don't run (SPEC §3.5, §13.6).
 */
class ExternalServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(ExternalService::query()->withCount('sites'), $request)
            ->allowedFilters(
                AllowedFilter::callback('show', function (Builder $query, mixed $value): void {
                    if ($value === 'deleted') {
                        $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('external_services.deleted_at');
                    }
                }),
            )
            ->allowedSorts('name', 'sites_count')
            ->defaultSort('name');

        return Inertia::render('external-services/Index', [
            ...TableQuery::props($request, $query, ExternalServiceRowData::class, ['show'], 'name'),
            'counts' => [
                'all' => ExternalService::query()->count(),
                'deleted' => ExternalService::onlyTrashed()->count(),
            ],
        ]);
    }

    /**
     * A service's page: where the traffic through it goes, the sites behind
     * it, and what goes down with it.
     */
    public function show(ExternalService $externalService): Response
    {
        $sites = $externalService->sites()->with(['frontService', 'server', 'application'])->orderBy('hostname')->get();

        $backends = $sites
            ->groupBy(fn (Site $site): int => $site->server_id ?? 0)
            ->map(function (Collection $group, int $serverId): BackendGroupData {
                /** @var Site $first */
                $first = $group->first();

                return new BackendGroupData(
                    server_id: $serverId === 0 ? null : $serverId,
                    server: $first->server?->name,
                    sites_count: $group->count(),
                    first_site: $first->display_hostname,
                );
            })
            ->sortBy(fn (BackendGroupData $group): string => ($group->server === null ? '1' : '0').$group->server)
            ->values();

        return Inertia::render('external-services/Show', [
            'service' => ExternalServiceData::fromModel($externalService),
            'backends' => $backends,
            'sites' => $sites->map(ServedSiteData::fromModel(...))->values(),
            'radius' => BlastRadius::of($sites),
        ]);
    }

    public function create(Request $request): Response
    {
        $ip = $request->query('ip');

        return Inertia::render('external-services/Form', [
            'form' => ExternalServiceFormData::blank(is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? [$ip] : []),
            'return_to' => ReturnTo::from($request),
        ]);
    }

    public function store(ExternalServiceRequest $request): RedirectResponse
    {
        $service = ExternalService::query()->create($this->attributes($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "External service {$service->name} added."]);
        $returnTo = ReturnTo::from($request);

        return $returnTo !== null ? redirect($returnTo) : to_route('external-services.show', $service);
    }

    public function edit(ExternalService $externalService): Response
    {
        return Inertia::render('external-services/Form', ['form' => ExternalServiceFormData::fromModel($externalService), 'return_to' => null]);
    }

    public function update(ExternalServiceRequest $request, ExternalService $externalService): RedirectResponse
    {
        $externalService->update($this->attributes($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "External service {$externalService->name} saved."]);

        return to_route('external-services.show', $externalService);
    }

    /**
     * A service used by live sites can't be deleted (SPEC §3.5).
     */
    public function destroy(ExternalService $externalService): RedirectResponse
    {
        $sites = $externalService->sites()->count();

        if ($sites > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$externalService->name} is in front of {$sites} ".str('site')->plural($sites).'. Change their serving path first.']);

            return back();
        }

        $externalService->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "External service {$externalService->name} deleted."]);

        return to_route('external-services.index');
    }

    public function restore(ExternalService $externalService): RedirectResponse
    {
        if (ExternalService::query()->where('name', $externalService->name)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Can't restore {$externalService->name}: another external service has that name."]);

            return back();
        }

        $externalService->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "External service {$externalService->name} restored."]);

        return to_route('external-services.show', $externalService);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ExternalServiceRequest $request): array
    {
        return $request->safe()->only(['name', 'kind', 'ranges', 'ranges_source', 'cname_suffixes', 'response_headers', 'notes']);
    }
}
