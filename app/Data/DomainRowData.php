<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Domain;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A domain with its subdomains (Domains list, SPEC §13.6). Expects
 * `sites.frontService` and `sites.server` loaded.
 */
class DomainRowData extends Data
{
    /**
     * @param  list<DomainSiteData>  $sites
     */
    public function __construct(
        public int $id,
        public string $name,
        public Status $status,
        public ?string $expires_at,
        public ?int $expires_in_days,
        public ?string $registrar,
        public int $failing_count,
        public int $warning_count,
        public array $sites,
        public bool $deleted,
    ) {}

    public static function fromModel(Domain $domain): self
    {
        $sites = $domain->sites
            ->sortBy(fn (Site $site): string => ($site->hostname === $domain->name ? '0' : '1').$site->hostname);

        return new self(
            id: $domain->id,
            name: $domain->display_name,
            status: $domain->status,
            expires_at: $domain->expires_at?->toDateString(),
            expires_in_days: self::daysUntil($domain),
            registrar: $domain->registrar,
            failing_count: $sites->filter(fn (Site $site): bool => $site->status === Status::Error)->count(),
            warning_count: $sites->filter(fn (Site $site): bool => $site->status === Status::Warning)->count(),
            sites: array_values($sites->map(DomainSiteData::fromModel(...))->all()),
            deleted: $domain->trashed(),
        );
    }

    /**
     * Calendar days in Europe/Ljubljana (SPEC §9.3).
     */
    public static function daysUntil(Domain $domain): ?int
    {
        if ($domain->expires_at === null) {
            return null;
        }

        $today = CarbonImmutable::now(config('sextant.display_timezone'))->startOfDay();
        $expires = CarbonImmutable::parse($domain->expires_at->toDateString(), config('sextant.display_timezone'));

        return (int) $today->diffInDays($expires, false);
    }
}
