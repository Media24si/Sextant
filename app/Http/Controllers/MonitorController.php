<?php

namespace App\Http\Controllers;

use App\Checks\CheckerRegistry;
use App\Data\Monitors\MonitorDetailData;
use App\Data\Monitors\MonitorRowData;
use App\Enums\Status;
use App\Http\Requests\MonitorRequest;
use App\Models\Concerns\Monitorable;
use App\Models\Monitor;
use App\Models\MonitorState;
use App\Monitoring\MonitorClaimer;
use App\Monitoring\MonitorPresenter;
use App\Monitoring\ResourceStatus;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Monitors (SPEC §13.6): the global list, the drawer, the form and Run now,
 * with the effects of §6.4.
 */
class MonitorController extends Controller
{
    private const FILTERS = ['show', 'checker', 'type'];

    public function __construct(private MonitorPresenter $presenter) {}

    public function index(Request $request, CheckerRegistry $registry): Response
    {
        $query = QueryBuilder::for(Monitor::query()->whereHasMorph('monitorable', '*')->with(['state', 'monitorable']), $request)
            ->allowedFilters(
                AllowedFilter::callback('show', function (Builder $query, mixed $value): void {
                    match ($value) {
                        'pending' => $query->where('enabled', true)->whereHas('state', fn (Builder $state) => $state->whereIn('last_result_status', [Status::Warning, Status::Error])),
                        'unknown' => $query->where('enabled', true)->whereHas('state', fn (Builder $state) => $state->where('status', Status::Unknown)),
                        'disabled' => $query->where('enabled', false),
                        'muted' => $query->where('muted_until', '>', now()),
                        default => null,
                    };
                }),
                AllowedFilter::exact('checker'),
                AllowedFilter::exact('type', 'monitorable_type'),
            )
            ->allowedSorts('checker', 'next_run_at')
            ->defaultSort('checker');

        $base = fn () => Monitor::query()->whereHasMorph('monitorable', '*');

        return Inertia::render('monitors/Index', [
            ...TableQuery::props($request, $query, MonitorRowData::class, self::FILTERS, 'checker', fn (Monitor $monitor): MonitorRowData => $this->presenter->row($monitor)),
            'counts' => [
                'all' => $base()->count(),
                'pending' => $base()->where('enabled', true)->whereHas('state', fn (Builder $state) => $state->whereIn('last_result_status', [Status::Warning, Status::Error]))->count(),
                'unknown' => $base()->where('enabled', true)->whereHas('state', fn (Builder $state) => $state->where('status', Status::Unknown))->count(),
                'disabled' => $base()->where('enabled', false)->count(),
                'muted' => $base()->where('muted_until', '>', now())->count(),
            ],
            'checkers' => array_map(
                fn (string $key): array => ['value' => $key, 'label' => $registry->name($key)],
                array_keys($registry->entries()),
            ),
        ]);
    }

    /**
     * The drawer's data (JSON).
     */
    public function show(Monitor $monitor): MonitorDetailData
    {
        return $this->presenter->detail($monitor);
    }

    public function store(MonitorRequest $request, MonitorClaimer $claimer): RedirectResponse
    {
        /** @var Model&Monitorable $resource */
        $resource = $request->resource();

        $monitor = new Monitor([
            ...$request->safe()->only(['checker', 'label', 'interval_minutes', 'failures_before_incident', 'successes_before_resolve']),
            'arguments' => $request->arguments(),
            'enabled' => $request->boolean('enabled', true),
        ]);
        $monitor->next_run_at = $monitor->enabled ? now()->startOfSecond() : null;
        $monitor->monitorable()->associate($resource);
        $monitor->save();

        $claimer->claimNow($monitor);
        ResourceStatus::refresh($resource);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$this->presenter->name($monitor)} monitor added."]);

        return back();
    }

    /**
     * §6.4: new settings run now; disabling stops runs; enabling resets the counters.
     */
    public function update(MonitorRequest $request, Monitor $monitor, MonitorClaimer $claimer): RedirectResponse
    {
        $wasEnabled = $monitor->enabled;

        $monitor->fill([
            ...$request->safe()->only(['label', 'interval_minutes', 'failures_before_incident', 'successes_before_resolve']),
            'arguments' => $request->arguments(),
            'enabled' => $request->boolean('enabled'),
        ]);
        $monitor->next_run_at = $monitor->enabled ? now()->startOfSecond() : null;
        $monitor->save();

        if ($monitor->enabled && ! $wasEnabled) {
            MonitorState::query()->whereKey($monitor->id)->update(['consecutive_failures' => 0, 'consecutive_successes' => 0, 'consecutive_unknowns' => 0]);
        }

        if ($monitor->enabled) {
            $claimer->claimNow($monitor);
        }

        $this->refreshResource($monitor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$this->presenter->name($monitor)} monitor saved."]);

        return back();
    }

    public function destroy(Monitor $monitor): RedirectResponse
    {
        $monitor->delete();
        $this->refreshResource($monitor);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$this->presenter->name($monitor)} monitor deleted."]);

        return back();
    }

    /**
     * Run now (§5.1): claimed like the dispatcher would.
     */
    public function run(Monitor $monitor, MonitorClaimer $claimer): RedirectResponse
    {
        $queued = $claimer->claimNow($monitor);

        Inertia::flash('toast', $queued
            ? ['type' => 'success', 'message' => "{$this->presenter->name($monitor)} is running. The result shows here in a few seconds."]
            : ['type' => 'error', 'message' => 'Only enabled monitors run.']);

        return back();
    }

    private function refreshResource(Monitor $monitor): void
    {
        $resource = $monitor->resource();

        if ($resource !== null) {
            ResourceStatus::refresh($resource);
        }
    }
}
