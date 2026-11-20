<?php

namespace App\Actions\Sites;

use App\Detection\NormalizedUrl;
use App\Detection\ProposedMonitors;
use App\Events\DomainAdded;
use App\Events\SiteAdded;
use App\Models\Domain;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Saves reviewed sites in one transaction (SPEC §10.4): the domain if it's
 * new, then each site. Observers record the activity; `SiteAdded` and
 * `DomainAdded` fire after commit so Phase 3 can attach monitors.
 *
 * @phpstan-type SiteInput array{hostname: string, scheme: string, port?: int|null, path?: string|null, front_service_id?: int|null, server_id?: int|null, description?: string|null, github_url?: string|null, expected_target?: string|null}
 */
class AddSites
{
    public function __construct(private ProposedMonitors $monitors) {}

    /**
     * @param  string  $domainName  registrable domain, lowercase punycode
     * @param  list<SiteInput>  $sites
     * @param  string|null  $notes  for a new domain (Add domain)
     * @return list<Site>
     */
    public function handle(string $domainName, array $sites, ?string $notes = null): array
    {
        return DB::transaction(function () use ($domainName, $sites, $notes): array {
            $domain = Domain::query()->where('name', $domainName)->first();

            if ($domain === null) {
                $domain = Domain::query()->create(['name' => $domainName, 'notes' => $notes]);
                DomainAdded::dispatch($domain, $this->monitors->forDomain());
            }

            return array_map(fn (array $input): Site => $this->addSite($domain, $input), $sites);
        });
    }

    /**
     * @param  SiteInput  $input
     */
    private function addSite(Domain $domain, array $input): Site
    {
        $site = $domain->sites()->create([
            'hostname' => $input['hostname'],
            'scheme' => $input['scheme'],
            'port' => $input['port'] ?? null,
            'front_service_id' => $input['front_service_id'] ?? null,
            'server_id' => $input['server_id'] ?? null,
            'description' => $input['description'] ?? null,
            'github_url' => $input['github_url'] ?? null,
        ]);

        $url = new NormalizedUrl($site->scheme, $site->hostname, $site->port, $input['path'] ?? '/');
        SiteAdded::dispatch($site, $this->monitors->forSite($url, $input['expected_target'] ?? null));

        return $site;
    }
}
