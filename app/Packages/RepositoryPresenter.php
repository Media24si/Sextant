<?php

namespace App\Packages;

use App\Data\PackageMinimumRowData;
use App\Data\Repositories\RepositoryPackageData;
use App\Data\Repositories\RepositoryRowData;
use App\Data\Repositories\RepositorySummaryData;
use App\Data\Repositories\RepositoryUserData;
use App\Models\Application;
use App\Models\GithubToken;
use App\Models\PackageMinimum;
use App\Models\Repository;
use App\Models\RepositoryPackage;
use App\Models\Site;
use Illuminate\Support\Collection;

/** Turns repositories into what the Repositories, application and site pages show (SPEC §13.6). */
class RepositoryPresenter
{
    public function __construct(private PackageMinimums $minimums) {}

    /**
     * @param  Collection<int, Repository>  $repositories  with `packages_count`
     * @return list<RepositoryRowData>
     */
    public function rows(Collection $repositories): array
    {
        $usage = $this->usage($repositories);
        $tokens = GithubToken::query()->get()->keyBy(fn (GithubToken $token): string => strtolower($token->owner));
        $below = $this->minimums->watched(array_values($repositories->map(fn (Repository $repository): int => $repository->id)->all()))
            ->filter(fn (RepositoryPackage $package): bool => $this->minimums->isBelow($package))
            ->countBy('repository_id');

        return array_values($repositories->map(fn (Repository $repository): RepositoryRowData => RepositoryRowData::fromModel(
            $repository,
            (int) $below->get($repository->id, 0),
            $usage[$repository->id] ?? [],
            $tokens->get(strtolower($repository->owner)),
        ))->all());
    }

    /**
     * Each package minimum with the repositories below it, for the Minimum
     * versions page and the dashboard.
     *
     * @return list<PackageMinimumRowData>
     */
    public function minimumRows(): array
    {
        $watched = $this->minimums->watched()->load('repository');

        return array_values(PackageMinimum::query()->with('setBy')->orderBy('ecosystem')->orderBy('name')->get()
            ->map(fn (PackageMinimum $minimum): PackageMinimumRowData => PackageMinimumRowData::fromModel($minimum, array_values($watched
                ->filter(fn (RepositoryPackage $package): bool => $package->ecosystem === $minimum->ecosystem
                    && strcasecmp($package->name, $minimum->name) === 0
                    && $this->minimums->isBelow($package))
                ->map(fn (RepositoryPackage $package): array => ['id' => $package->repository_id, 'name' => $package->repository->fullName(), 'version' => $package->version])
                ->unique('id')
                ->all())))
            ->all());
    }

    /**
     * Packages that have a minimum, below first, then by name.
     *
     * @return list<RepositoryPackageData>
     */
    public function watched(Repository $repository): array
    {
        return array_values($this->minimums->watched([$repository->id])
            ->map(fn (RepositoryPackage $package): RepositoryPackageData => RepositoryPackageData::fromModel($package, $this->minimums))
            ->sortBy([['below_minimum', 'desc'], ['name', 'asc']])
            ->values()
            ->all());
    }

    public function summary(?Repository $repository, ?string $via = null): ?RepositorySummaryData
    {
        if ($repository === null || $repository->trashed()) {
            return null;
        }

        return new RepositorySummaryData(
            id: $repository->id,
            full_name: $repository->fullName(),
            status: $repository->status,
            read_at: $repository->read_at?->toIso8601String(),
            lock_files: $repository->lock_files ?? [],
            packages_count: $repository->packages()->count(),
            watched: $this->watched($repository),
            via: $via,
        );
    }

    /**
     * Applications and sites using each repository: by their own link, or a
     * site through its application's.
     *
     * @param  Collection<int, Repository>  $repositories
     * @return array<int, list<RepositoryUserData>>
     */
    private function usage(Collection $repositories): array
    {
        $ids = $repositories->keyBy(fn (Repository $repository): string => strtolower($repository->url))->map->id;
        $urls = $repositories->pluck('url')->all();
        $applications = Application::query()->whereIn('github_url', $urls)->orderBy('name')->get();
        $usage = [];

        foreach ($applications as $application) {
            $id = $ids->get(strtolower((string) $application->github_url));

            if ($id !== null) {
                $usage[$id][] = new RepositoryUserData('application', $application->id, $application->name);
            }
        }

        $sites = Site::query()
            ->where(fn ($query) => $query->whereIn('github_url', $urls)
                ->orWhere(fn ($query) => $query->whereNull('github_url')->whereIn('application_id', $applications->modelKeys())))
            ->with('application')
            ->orderBy('hostname')
            ->get();

        foreach ($sites as $site) {
            $own = $site->github_url !== null;
            $id = $ids->get(strtolower((string) ($own ? $site->github_url : $site->application?->github_url)));

            if ($id !== null) {
                $usage[$id][] = new RepositoryUserData('site', $site->id, $site->display_hostname, $own ? null : $site->application?->name);
            }
        }

        return $usage;
    }
}
