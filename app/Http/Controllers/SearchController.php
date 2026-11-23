<?php

namespace App\Http\Controllers;

use App\Data\SearchResultData;
use App\Enums\SearchResultType;
use App\Models\Application;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerSoftware;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Global search (SPEC §13.8): up to 20 results grouped by type. Plain LIKE
 * queries are fast enough at this size. Matching on software versions joins
 * in Phase 7.
 */
class SearchController extends Controller
{
    private const LIMIT = 20;

    private const PER_TYPE = 8;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $terms = $this->terms($query);

        $results = collect([
            ...$this->servers($terms),
            ...$this->externalServices($terms),
            ...$this->domains($terms),
            ...$this->sites($terms),
            ...$this->applications($terms),
        ])->take(self::LIMIT)->values();

        return response()->json(['results' => SearchResultData::collect($results)]);
    }

    /**
     * "nginx 1.24.0" when the search matched one of the server's versions.
     *
     * @param  list<string>  $terms
     */
    private function matchingVersion(Server $server, array $terms): ?string
    {
        // Terms come as LIKE patterns: "%8.2.20%".
        $needles = array_map(fn (string $term): string => stripcslashes(trim($term, '%')), $terms);
        $row = $server->software->first(fn (ServerSoftware $row): bool => collect($needles)
            ->contains(fn (string $needle): bool => str_contains(strtolower($row->version.' '.$row->package_version), $needle)));

        return $row === null ? null : "{$row->label()} {$row->version}";
    }

    /**
     * The query as typed, plus its punycode form for Unicode hostnames.
     *
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $terms = [mb_strtolower($query)];

        if (preg_match('/[^\x20-\x7e]/', $query) === 1) {
            $ascii = idn_to_ascii(mb_strtolower($query), IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if ($ascii !== false && $ascii !== $terms[0]) {
                $terms[] = $ascii;
            }
        }

        return array_map(fn (string $term): string => '%'.addcslashes($term, '%_\\').'%', $terms);
    }

    /**
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $terms
     */
    private function whereLike(Builder $query, array $columns, array $terms): void
    {
        // Grouped, so the ORs never escape a relation's join condition.
        $query->where(function (Builder $query) use ($columns, $terms): void {
            foreach ($columns as $column) {
                foreach ($terms as $term) {
                    $query->orWhere($column, 'like', $term);
                }
            }
        });
    }

    /**
     * Name, hostname, any address, OS (chosen or reported).
     *
     * @param  list<string>  $terms
     * @return list<SearchResultData>
     */
    private function servers(array $terms): array
    {
        return array_values(Server::query()
            ->where(function (Builder $query) use ($terms): void {
                $this->whereLike($query, ['name', 'hostname', 'os_reported'], $terms);
                $query->orWhereHas('addresses', fn (Builder $addresses) => $this->whereLike($addresses, ['ip'], $terms))
                    ->orWhereHas('os', fn (Builder $os) => $this->whereLike($os, ['label'], $terms))
                    // Search by software version, e.g. "1.24.0" or "8.2.20" (SPEC §13.8).
                    ->orWhereHas('software', fn (Builder $software) => $this->whereLike($software, ['version', 'package_version'], $terms));
            })
            ->with(['primaryAddress', 'os', 'software'])
            ->withCount('sites')
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Server $server): SearchResultData => new SearchResultData(
                type: SearchResultType::Server,
                id: $server->id,
                label: $server->name,
                context: collect([
                    $server->primaryAddress?->ip,
                    $this->matchingVersion($server, $terms) ?? $server->os->label ?? $server->os_reported,
                    $this->count((int) $server->getAttribute('sites_count'), 'site'),
                ])->filter()->implode(' · '),
                status: $server->status,
                url: route('servers.show', $server, false),
            ))
            ->all());
    }

    /**
     * @param  list<string>  $terms
     * @return list<SearchResultData>
     */
    private function externalServices(array $terms): array
    {
        return array_values(ExternalService::query()
            ->where(fn (Builder $query) => $this->whereLike($query, ['name'], $terms))
            ->withCount('sites')
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (ExternalService $service): SearchResultData => new SearchResultData(
                type: SearchResultType::ExternalService,
                id: $service->id,
                label: $service->name,
                context: 'External service · in front of '.$this->count((int) $service->getAttribute('sites_count'), 'site'),
                status: null,
                url: route('external-services.show', $service, false),
            ))
            ->all());
    }

    /**
     * @param  list<string>  $terms
     * @return list<SearchResultData>
     */
    private function domains(array $terms): array
    {
        return array_values(Domain::query()
            ->where(fn (Builder $query) => $this->whereLike($query, ['name'], $terms))
            ->withCount('sites')
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Domain $domain): SearchResultData => new SearchResultData(
                type: SearchResultType::Domain,
                id: $domain->id,
                label: $domain->display_name,
                context: $this->count((int) $domain->getAttribute('sites_count'), 'site'),
                status: $domain->status,
                url: route('domains.show', $domain, false),
            ))
            ->all());
    }

    /**
     * Hostname or description.
     *
     * @param  list<string>  $terms
     * @return list<SearchResultData>
     */
    private function sites(array $terms): array
    {
        return array_values(Site::query()
            ->where(fn (Builder $query) => $this->whereLike($query, ['hostname', 'description'], $terms))
            ->with(['frontService', 'server'])
            ->orderBy('hostname')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Site $site): SearchResultData => new SearchResultData(
                type: SearchResultType::Site,
                id: $site->id,
                label: $site->display_hostname,
                context: collect([
                    $site->description,
                    ($site->frontService !== null ? $site->frontService->name.' → ' : '').($site->server->name ?? 'backend not assigned'),
                ])->filter()->implode(' · '),
                status: $site->status,
                url: route('sites.show', $site, false),
            ))
            ->all());
    }

    /**
     * Name or description.
     *
     * @param  list<string>  $terms
     * @return list<SearchResultData>
     */
    private function applications(array $terms): array
    {
        return array_values(Application::query()
            ->where(fn (Builder $query) => $this->whereLike($query, ['name', 'description'], $terms))
            ->with('sites')
            ->orderBy('name')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Application $application): SearchResultData => new SearchResultData(
                type: SearchResultType::Application,
                id: $application->id,
                label: $application->name,
                context: collect([
                    $application->description,
                    'Application · '.$this->count($application->sites->count(), 'site'),
                ])->filter()->implode(' · '),
                status: $application->sites->isEmpty() ? null : $application->status(),
                url: route('applications.show', $application, false),
            ))
            ->all());
    }

    private function count(int $count, string $noun): string
    {
        return $count.' '.Str::plural($noun, $count);
    }
}
