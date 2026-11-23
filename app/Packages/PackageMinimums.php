<?php

namespace App\Packages;

use App\Models\PackageMinimum;
use App\Models\RepositoryPackage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Which locked packages are below the minimum an admin set (SPEC §11.9).
 * Each package has one minimum with a version per major; a package version
 * is compared with the version of the nearest listed major at or below its
 * own. Cached per request or job.
 */
class PackageMinimums
{
    /** @var Collection<string, PackageMinimum>|null */
    private ?Collection $minimums = null;

    public function for(RepositoryPackage $package): ?PackageMinimum
    {
        return $this->all()->get(self::key($package->ecosystem->value, $package->name));
    }

    /** The listed version this package is compared with, or null without a minimum. */
    public function floorFor(RepositoryPackage $package): ?string
    {
        return $this->for($package)?->floorFor($package->version);
    }

    public function isBelow(RepositoryPackage $package): bool
    {
        $floor = $this->floorFor($package);

        return $floor !== null && PackageVersion::isBelow($package->version, $floor);
    }

    /**
     * In the same shape as server software below a minimum, so incidents and
     * Discord show both alike.
     *
     * @param  iterable<RepositoryPackage>  $packages
     * @return list<array{software: string, branch: string, version: string, min_version: string, cve_url: string|null}>
     */
    public function below(iterable $packages): array
    {
        $below = [];

        foreach ($packages as $package) {
            if ($this->isBelow($package)) {
                $below[] = [
                    'software' => $package->name,
                    'branch' => (string) PackageVersion::branch($package->version),
                    'version' => $package->version,
                    'min_version' => (string) $this->floorFor($package),
                    'cve_url' => $this->for($package)?->cve_url,
                ];
            }
        }

        return $below;
    }

    /**
     * Packages that have a minimum, of the given repositories (or all), for
     * pages that show only those.
     *
     * @param  list<int>|null  $repositoryIds
     * @return EloquentCollection<int, RepositoryPackage>
     */
    public function watched(?array $repositoryIds = null): EloquentCollection
    {
        if ($this->all()->isEmpty()) {
            return new EloquentCollection;
        }

        return RepositoryPackage::query()
            ->when($repositoryIds !== null, fn ($query) => $query->whereIn('repository_id', $repositoryIds))
            ->where(function ($query): void {
                foreach ($this->all() as $minimum) {
                    $query->orWhere(fn ($query) => $query->where('ecosystem', $minimum->ecosystem->value)->where('name', $minimum->name));
                }
            })
            ->whereHas('repository')
            ->get();
    }

    /**
     * Repository ids with a package below a minimum.
     *
     * @return list<int>
     */
    public function repositoryIdsBelow(): array
    {
        return array_values($this->watched()
            ->filter(fn (RepositoryPackage $package): bool => $this->isBelow($package))
            ->pluck('repository_id')
            ->unique()
            ->values()
            ->all());
    }

    /** Forget cached minimums, e.g. after one changed. */
    public function flush(): void
    {
        $this->minimums = null;
    }

    /**
     * @return Collection<string, PackageMinimum>
     */
    private function all(): Collection
    {
        return $this->minimums ??= PackageMinimum::query()->get()
            ->keyBy(fn (PackageMinimum $minimum): string => self::key($minimum->ecosystem->value, $minimum->name));
    }

    private static function key(string $ecosystem, string $name): string
    {
        return $ecosystem.':'.strtolower($name);
    }
}
