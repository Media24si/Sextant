<?php

namespace App\Http\Controllers;

use App\Data\ActivityItemData;
use App\Data\DomainFormData;
use App\Data\DomainRowData;
use App\Http\Requests\DomainRequest;
use App\Models\Activity;
use App\Models\Domain;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Domains and their subdomains (SPEC §13.6). Phase 1 adds domains by hand;
 * Phase 2's Add domain (§10.5) brings the registry lookup.
 */
class DomainController extends Controller
{
    private const ACTIVITY_SHOWN = 20;

    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(Domain::query()->with(['sites.frontService', 'sites.server']), $request)
            ->allowedFilters(
                // Matches the domain, its sites, their backends and front services.
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $term = '%'.(is_array($value) ? implode(',', $value) : (string) $value).'%';

                    $query->where(fn (Builder $query) => $query
                        ->where('name', 'like', $term)
                        ->orWhereHas('sites', fn (Builder $sites) => $sites
                            ->where('hostname', 'like', $term)
                            ->orWhere('description', 'like', $term)
                            ->orWhereHas('server', fn (Builder $server) => $server->where('name', 'like', $term))
                            ->orWhereHas('frontService', fn (Builder $front) => $front->where('name', 'like', $term))));
                }),
                AllowedFilter::callback('show', function (Builder $query, mixed $value): void {
                    if ($value === 'deleted') {
                        $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('domains.deleted_at');
                    }
                }),
            )
            ->allowedSorts('name', 'expires_at')
            ->defaultSort('name');

        return Inertia::render('domains/Index', [
            ...TableQuery::props($request, $query, DomainRowData::class, ['search', 'show'], 'name'),
            'counts' => [
                'all' => Domain::query()->count(),
                'sites' => Domain::query()->withCount('sites')->get()->sum('sites_count'),
                'deleted' => Domain::onlyTrashed()->count(),
            ],
        ]);
    }

    public function show(Domain $domain): Response
    {
        $domain->load(['sites.frontService', 'sites.server']);

        $siteIds = $domain->sites()->withTrashed()->pluck('id');

        return Inertia::render('domains/Show', [
            'domain' => DomainRowData::fromModel($domain),
            'notes' => $domain->notes,
            'activity' => Activity::query()
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('subject_type', 'domain')->where('subject_id', $domain->id))
                    ->orWhere(fn ($query) => $query->where('subject_type', 'site')->whereIn('subject_id', $siteIds)))
                ->with('user')
                ->latest('id')
                ->limit(self::ACTIVITY_SHOWN)
                ->get()
                ->map(ActivityItemData::fromModel(...)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('domains/Form', ['form' => DomainFormData::blank()]);
    }

    public function store(DomainRequest $request): RedirectResponse
    {
        $domain = Domain::query()->create($request->safe()->only(['name', 'notes']));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Domain {$domain->display_name} added."]);

        return to_route('domains.show', $domain);
    }

    public function edit(Domain $domain): Response
    {
        return Inertia::render('domains/Form', ['form' => DomainFormData::fromModel($domain)]);
    }

    public function update(DomainRequest $request, Domain $domain): RedirectResponse
    {
        $domain->update($request->safe()->only(['name', 'notes']));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Domain {$domain->display_name} saved."]);

        return to_route('domains.show', $domain);
    }

    /**
     * A domain with live sites can't be deleted (SPEC §3.7).
     */
    public function destroy(Domain $domain): RedirectResponse
    {
        $sites = $domain->sites()->count();

        if ($sites > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$domain->display_name} still has {$sites} ".str('site')->plural($sites).'. Delete them first.']);

            return back();
        }

        $domain->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Domain {$domain->display_name} deleted."]);

        return to_route('domains.index');
    }

    public function restore(Domain $domain): RedirectResponse
    {
        if (Domain::query()->where('name', $domain->name)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Can't restore {$domain->display_name}: it was added again."]);

            return back();
        }

        $domain->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Domain {$domain->display_name} restored."]);

        return to_route('domains.show', $domain);
    }
}
