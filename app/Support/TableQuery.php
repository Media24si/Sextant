<?php

namespace App\Support;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Builds the props of a server-side DataTable (SPEC §13.5) from a
 * spatie/laravel-query-builder query: one page of rows as laravel-data
 * objects, the active filters and the active sort.
 */
class TableQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  QueryBuilder<*>  $query  with allowedFilters, allowedSorts and defaultSort applied
     * @param  class-string<Data>  $rowData
     * @param  list<string>  $filterNames  the allowed filter names, echoed back to the page
     * @return array{rows: PaginatedDataCollection<array-key, Data>, filters: array<string, string>, sort: string}
     */
    public static function props(
        Request $request,
        QueryBuilder $query,
        string $rowData,
        array $filterNames,
        string $defaultSort,
    ): array {
        return [
            'rows' => $rowData::collect(
                $query->paginate(self::PER_PAGE)->withQueryString(),
                PaginatedDataCollection::class,
            ),
            'filters' => self::filters($request, $filterNames),
            'sort' => self::sort($request, $defaultSort),
        ];
    }

    /**
     * @param  list<string>  $filterNames
     * @return array<string, string>
     */
    private static function filters(Request $request, array $filterNames): array
    {
        $input = $request->query('filter');

        if (! is_array($input)) {
            return [];
        }

        $filters = [];

        foreach ($filterNames as $name) {
            if (isset($input[$name]) && is_string($input[$name]) && $input[$name] !== '') {
                $filters[$name] = $input[$name];
            }
        }

        return $filters;
    }

    private static function sort(Request $request, string $defaultSort): string
    {
        $sort = $request->query('sort');

        return is_string($sort) && $sort !== '' ? $sort : $defaultSort;
    }
}
