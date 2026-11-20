<?php

namespace App\Http\Controllers;

use App\Data\UserRowData;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Temporary demo of the server-side DataTable (P0.11), over the users table.
 * Removed when the Servers list (P1.10) replaces it.
 */
class DemoTableController extends Controller
{
    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(User::class, $request)
            ->allowedFilters(
                AllowedFilter::exact('role'),
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $term = is_array($value) ? implode(',', $value) : (string) $value;
                    $query->where('name', 'like', '%'.$term.'%');
                }),
            )
            ->allowedSorts('name', 'role', 'created_at')
            ->defaultSort('name');

        $counts = User::query()->toBase()
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return Inertia::render('demo/Table', [
            ...TableQuery::props($request, $query, UserRowData::class, ['role', 'search'], 'name'),
            'counts' => [
                'all' => (int) $counts->sum(),
                'admin' => (int) ($counts[UserRole::Admin->value] ?? 0),
                'reader' => (int) ($counts[UserRole::Reader->value] ?? 0),
            ],
        ]);
    }

    /**
     * A harmless admin-only action, so the Phase 0 exit check has a mutation
     * that readers can't see and get 403 on.
     */
    public function ping(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pong. You can change things here.')]);

        return back();
    }
}
