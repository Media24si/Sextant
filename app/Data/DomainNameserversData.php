<?php

namespace App\Data;

use App\Models\Domain;
use App\Models\Monitor;
use Spatie\LaravelData\Data;

/**
 * Where a domain's DNS is hosted, from the latest checks: the live NS set
 * from `domain.nameservers` (SPEC §9.4), else the registry's list from
 * `domain.expiration` (§9.3). Empty until one of them has run.
 */
class DomainNameserversData extends Data
{
    /**
     * @param  list<string>  $nameservers
     * @param  string|null  $source  `dns` or `registry`
     */
    public function __construct(
        public array $nameservers,
        public ?string $source,
    ) {}

    public static function fromModel(Domain $domain): self
    {
        $monitors = $domain->monitors()
            ->whereIn('checker', ['domain.nameservers', 'domain.expiration'])
            ->with('state')
            ->get();

        foreach (['domain.nameservers' => ['nameservers_live', 'dns'], 'domain.expiration' => ['nameservers', 'registry']] as $checker => [$field, $source]) {
            $nameservers = self::listFrom($monitors->where('checker', $checker), $field);

            if ($nameservers !== []) {
                return new self($nameservers, $source);
            }
        }

        return new self([], null);
    }

    /**
     * The most recently checked non-empty list among these monitors.
     *
     * @param  iterable<Monitor>  $monitors
     * @return list<string>
     */
    private static function listFrom(iterable $monitors, string $field): array
    {
        $latest = collect($monitors)
            ->filter(fn (Monitor $monitor): bool => is_array($monitor->state?->data[$field] ?? null) && $monitor->state->data[$field] !== [])
            ->sortByDesc(fn (Monitor $monitor): int => $monitor->state?->checked_at?->getTimestamp() ?? 0)
            ->first();

        if ($latest === null) {
            return [];
        }

        $names = array_map(fn (mixed $name): string => rtrim(strtolower(trim((string) $name)), '.'), $latest->state->data[$field]);
        $names = array_values(array_unique(array_filter($names)));
        sort($names);

        return $names;
    }
}
