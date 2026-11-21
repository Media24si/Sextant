<?php

namespace App\Detection;

use App\Data\Detection\BackendCandidateData;
use App\Data\Detection\BackendFindingData;
use App\Data\Detection\DetectedServerData;
use App\Enums\Confidence;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Behind a front service the backend can't be seen, so the most common backend
 * among the domain's other sites behind the same front is suggested (SPEC
 * §10.3). Used by detection and by `site.path` (§9.7).
 */
class BackendSuggester
{
    /**
     * @param  string  $domainName  for the explanation, in Unicode
     * @return array{BackendFindingData, list<BackendCandidateData>}
     */
    public function suggest(?Domain $domain, string $domainName, ExternalService $front, ?Site $ignore = null): array
    {
        $siblings = $domain === null ? collect() : Site::query()
            ->with('server')
            ->where('domain_id', $domain->id)
            ->where('front_service_id', $front->id)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->id))
            ->get();

        $candidates = $siblings
            ->filter(fn (Site $site): bool => $site->server !== null)
            ->groupBy('server_id')
            ->map(fn (Collection $sites): BackendCandidateData => new BackendCandidateData(
                (int) $sites->first()->server_id,
                (string) $sites->first()->server?->name,
                $sites->count(),
            ))
            ->sortBy([['sibling_count', 'desc'], ['name', 'asc']])
            ->values();

        $cantSee = "Sextant can't see behind {$front->name}.";

        if ($candidates->isEmpty()) {
            return [new BackendFindingData(null, Confidence::NotFound, "{$cantSee} No other {$domainName} site behind it has a backend yet, so pick the server."), []];
        }

        /** @var BackendCandidateData $top */
        $top = $candidates->first();

        if ($candidates->count() > 1 && $candidates->get(1)?->sibling_count === $top->sibling_count) {
            $tied = $candidates->where('sibling_count', $top->sibling_count)->pluck('name')->join(', ', ' and ');

            return [new BackendFindingData(null, Confidence::NotFound, "{$cantSee} The {$domainName} sites behind it are split between {$tied}, so pick the server."), array_values($candidates->all())];
        }

        $explanation = $siblings->count() === 1
            ? "{$cantSee} The other {$domainName} site behind {$front->name} uses {$top->name}."
            : "{$cantSee} {$top->sibling_count} of the {$siblings->count()} {$domainName} sites behind {$front->name} use {$top->name}.";

        return [new BackendFindingData(new DetectedServerData($top->server_id, $top->name), Confidence::Suggested, $explanation), array_values($candidates->all())];
    }
}
