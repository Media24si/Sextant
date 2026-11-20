<?php

namespace App\Http\Controllers;

use App\Data\ExternalServiceFormData;
use App\Data\ExternalServiceRowData;
use App\Http\Requests\ExternalServiceRequest;
use App\Models\ExternalService;
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

    public function create(): Response
    {
        return Inertia::render('external-services/Form', ['form' => ExternalServiceFormData::blank()]);
    }

    public function store(ExternalServiceRequest $request): RedirectResponse
    {
        $service = ExternalService::query()->create($this->attributes($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "External service {$service->name} added."]);

        return to_route('external-services.index');
    }

    public function edit(ExternalService $externalService): Response
    {
        return Inertia::render('external-services/Form', ['form' => ExternalServiceFormData::fromModel($externalService)]);
    }

    public function update(ExternalServiceRequest $request, ExternalService $externalService): RedirectResponse
    {
        $externalService->update($this->attributes($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "External service {$externalService->name} saved."]);

        return to_route('external-services.index');
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

        return to_route('external-services.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ExternalServiceRequest $request): array
    {
        return $request->safe()->only(['name', 'kind', 'ranges', 'ranges_source', 'cname_suffixes', 'response_headers', 'notes']);
    }
}
