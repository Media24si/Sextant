<?php

namespace App\Http\Controllers;

use App\Checks\CheckerRegistry;
use App\Data\FilterOptionData;
use App\Data\Incidents\IncidentRowData;
use App\Data\OptionData;
use App\Enums\Status;
use App\Models\Incident;
use App\Models\Server;
use App\Monitoring\IncidentManager;
use App\Monitoring\IncidentPresenter;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Incidents list and page (SPEC §13.6), and Acknowledge (§6.3). There's no
 * manual resolve: incidents resolve when the check recovers.
 */
class IncidentController extends Controller
{
    private const FILTERS = ['state', 'severity', 'type', 'server', 'checker'];

    public function __construct(private IncidentPresenter $presenter) {}

    public function index(Request $request, CheckerRegistry $registry): Response
    {
        $query = QueryBuilder::for(Incident::query()->with(IncidentPresenter::WITH)->withCount('children'), $request)
            ->allowedFilters(
                AllowedFilter::callback('state', function (Builder $query, mixed $value): void {
                    match ($value) {
                        'open' => $query->whereNull('resolved_at')->whereNull('acknowledged_at'),
                        'acknowledged' => $query->whereNull('resolved_at')->whereNotNull('acknowledged_at'),
                        'resolved' => $query->whereNotNull('resolved_at'),
                        default => null,
                    };
                }),
                AllowedFilter::exact('severity'),
                AllowedFilter::exact('type', 'monitorable_type'),
                AllowedFilter::exact('server', 'server_id'),
                AllowedFilter::exact('checker'),
            )
            ->allowedSorts('opened_at', 'resolved_at')
            ->defaultSort('-opened_at');

        return Inertia::render('incidents/Index', [
            ...TableQuery::props($request, $query, IncidentRowData::class, self::FILTERS, '-opened_at', fn (Incident $incident): IncidentRowData => $this->presenter->row($incident)),
            'counts' => [
                'all' => Incident::query()->count(),
                'open' => Incident::query()->open()->whereNull('acknowledged_at')->count(),
                'acknowledged' => Incident::query()->open()->whereNotNull('acknowledged_at')->count(),
                'resolved' => Incident::query()->resolved()->count(),
            ],
            'servers' => array_values(Server::query()->withTrashed()->orderBy('name')->get()
                ->map(fn (Server $server): OptionData => new OptionData($server->id, $server->name))->all()),
            'checkers' => array_map(
                fn (string $key): FilterOptionData => new FilterOptionData($key, $registry->name($key)),
                array_keys($registry->entries()),
            ),
            'severities' => [Status::Error->value, Status::Warning->value],
        ]);
    }

    public function show(Incident $incident): Response
    {
        return Inertia::render('incidents/Show', [
            'detail' => $this->presenter->detail($incident),
        ]);
    }

    public function acknowledge(Request $request, Incident $incident, IncidentManager $incidents): RedirectResponse
    {
        if (! $incident->isOpen() || $incident->isAcknowledged()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $incident->isOpen() ? 'Already acknowledged.' : 'This incident is resolved.']);

            return back();
        }

        $incidents->acknowledge($incident, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acknowledged. Reminders stop for this incident.']);

        return back();
    }
}
