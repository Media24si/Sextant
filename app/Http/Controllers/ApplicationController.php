<?php

namespace App\Http\Controllers;

use App\Data\ActivityItemData;
use App\Data\ApplicationFormData;
use App\Data\ApplicationRowData;
use App\Data\OptionData;
use App\Data\SiteRowData;
use App\Http\Requests\ApplicationRequest;
use App\Models\Activity;
use App\Models\Application;
use App\Packages\RepositoryPresenter;
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
 * Applications: one web app behind several hostnames (SPEC §3.19, §13.6).
 */
class ApplicationController extends Controller
{
    private const ACTIVITY_SHOWN = 20;

    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(Application::query()->with('sites')->withCount('sites'), $request)
            ->allowedFilters(
                // Matches the application and its hostnames.
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $term = '%'.(is_array($value) ? implode(',', $value) : (string) $value).'%';

                    $query->where(fn (Builder $query) => $query
                        ->where('name', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhereHas('sites', fn (Builder $sites) => $sites->where('hostname', 'like', $term)));
                }),
                AllowedFilter::callback('show', function (Builder $query, mixed $value): void {
                    if ($value === 'deleted') {
                        $query->withoutGlobalScope(SoftDeletingScope::class)->whereNotNull('applications.deleted_at');
                    }
                }),
            )
            ->allowedSorts('name', 'sites_count')
            ->defaultSort('name');

        return Inertia::render('applications/Index', [
            ...TableQuery::props($request, $query, ApplicationRowData::class, ['search', 'show'], 'name'),
            'counts' => [
                'all' => Application::query()->count(),
                'deleted' => Application::onlyTrashed()->count(),
            ],
        ]);
    }

    public function show(Application $application): Response
    {
        $application->load(['sites' => fn ($query) => $query->with(['domain', 'frontService', 'server'])->orderBy('hostname')]);

        $siteIds = $application->sites()->withTrashed()->pluck('id');

        return Inertia::render('applications/Show', [
            'application' => ApplicationRowData::fromModel($application),
            'notes' => $application->notes,
            'repository' => app(RepositoryPresenter::class)->summary($application->repository),
            'sites' => $application->sites->map(fn ($site) => SiteRowData::fromModel($site->setRelation('application', $application)))->values(),
            'activity' => Activity::query()
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('subject_type', 'application')->where('subject_id', $application->id))
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
        return Inertia::render('applications/Form', ['form' => ApplicationFormData::blank()]);
    }

    public function store(ApplicationRequest $request): RedirectResponse
    {
        $application = Application::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Application {$application->name} added."]);

        return to_route('applications.show', $application);
    }

    public function edit(Application $application): Response
    {
        return Inertia::render('applications/Form', ['form' => ApplicationFormData::fromModel($application)]);
    }

    public function update(ApplicationRequest $request, Application $application): RedirectResponse
    {
        $application->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Application {$application->name} saved."]);

        return to_route('applications.show', $application);
    }

    /**
     * An application with live sites can't be deleted (SPEC §3.19).
     */
    public function destroy(Application $application): RedirectResponse
    {
        $sites = $application->sites()->count();

        if ($sites > 0) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "{$application->name} still has {$sites} ".str('site')->plural($sites).'. Move them to another application or clear it first.']);

            return back();
        }

        $application->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Application {$application->name} deleted."]);

        return to_route('applications.index');
    }

    public function restore(Application $application): RedirectResponse
    {
        if (Application::query()->where('name', $application->name)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Can't restore {$application->name}: another application has that name."]);

            return back();
        }

        $application->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Application {$application->name} restored."]);

        return to_route('applications.show', $application);
    }

    /**
     * For selects on the site form and the Import page.
     *
     * @return list<OptionData>
     */
    public static function options(): array
    {
        return array_values(Application::query()->orderBy('name')->get()
            ->map(fn (Application $application): OptionData => new OptionData($application->id, $application->name))->all());
    }
}
