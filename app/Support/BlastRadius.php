<?php

namespace App\Support;

use App\Data\BlastRadiusData;
use App\Data\DependentData;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * "If web02 goes down": the sites that depend on a server or an external
 * service, and the applications and domains they make up, each with how
 * many of its live sites are affected.
 */
class BlastRadius
{
    /**
     * @param  Collection<int, Site>  $sites  live sites that depend on it
     */
    public static function of(Collection $sites): BlastRadiusData
    {
        $applications = Application::query()
            ->whereKey($sites->pluck('application_id')->filter()->unique()->values())
            ->withCount('sites')
            ->orderBy('name')
            ->get()
            ->map(fn (Application $application): DependentData => new DependentData(
                id: $application->id,
                name: $application->name,
                affected: $sites->where('application_id', $application->id)->count(),
                total: (int) $application->getAttribute('sites_count'),
            ));

        $domains = Domain::query()
            ->whereKey($sites->pluck('domain_id')->unique()->values())
            ->withCount('sites')
            ->orderBy('name')
            ->get()
            ->map(fn (Domain $domain): DependentData => new DependentData(
                id: $domain->id,
                name: $domain->display_name,
                affected: $sites->where('domain_id', $domain->id)->count(),
                total: (int) $domain->getAttribute('sites_count'),
            ));

        return new BlastRadiusData(
            sites: $sites->count(),
            applications: array_values($applications->all()),
            domains: array_values($domains->all()),
            hostnames: array_values($sites->map(fn (Site $site): string => $site->display_hostname)->sort()->all()),
        );
    }
}
