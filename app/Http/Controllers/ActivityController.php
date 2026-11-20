<?php

namespace App\Http\Controllers;

use App\Data\ActivityItemData;
use App\Data\OptionData;
use App\Enums\ActivityCategory;
use App\Models\Activity;
use App\Models\Server;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The change feed (SPEC §8, §13.6) with filters for category, type,
 * resource, server and user.
 */
class ActivityController extends Controller
{
    private const FILTERS = ['category', 'type', 'resource', 'server', 'user'];

    public function index(Request $request): Response
    {
        $query = QueryBuilder::for(Activity::query()->with('user'), $request)
            ->allowedFilters(
                AllowedFilter::exact('category'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('resource', 'subject_type'),
                AllowedFilter::exact('server', 'server_id'),
                AllowedFilter::callback('user', function ($query, mixed $value): void {
                    $value === 'sextant' ? $query->whereNull('user_id') : $query->where('user_id', (int) $value);
                }),
            )
            ->allowedSorts('created_at')
            ->defaultSort('-created_at');

        // Entries written in the same second keep their order.
        $query->getEloquentBuilder()->orderByDesc('id');

        return Inertia::render('activity/Index', [
            ...TableQuery::props($request, $query, ActivityItemData::class, self::FILTERS, '-created_at'),
            'categories' => array_map(fn (ActivityCategory $category): string => $category->value, ActivityCategory::cases()),
            'types' => Activity::query()->distinct()->orderBy('type')->pluck('type'),
            'servers' => Server::withTrashed()->orderBy('name')->get()
                ->map(fn (Server $server): OptionData => new OptionData($server->id, $server->name)),
            'users' => User::withTrashed()->whereIn('id', Activity::query()->select('user_id')->whereNotNull('user_id'))->orderBy('name')->get()
                ->map(fn (User $user): OptionData => new OptionData($user->id, $user->name)),
        ]);
    }
}
