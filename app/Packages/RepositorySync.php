<?php

namespace App\Packages;

use App\Enums\IncidentResolution;
use App\Github\GithubRepo;
use App\Models\Application;
use App\Models\Repository;
use App\Models\Site;
use App\Monitoring\DefaultMonitors;
use App\Monitoring\IncidentManager;
use App\Monitoring\MonitorEffects;

/**
 * Keeps `repositories` in step with the GitHub links of live sites and
 * applications (SPEC §3.21): one repository per link, with its default
 * monitor; soft-deleted (open incidents resolved) when nothing links to it,
 * restored when a link comes back. Sextant's own writes: no change feed.
 */
class RepositorySync
{
    public function __construct(
        private DefaultMonitors $defaults,
        private IncidentManager $incidents,
    ) {}

    /**
     * Sync after a site or application changed: always when the whole record
     * came or went with a link (created, deleted, restored), otherwise only
     * when the link itself was edited.
     */
    public static function afterChange(Site|Application $model, bool $wholeRecord = false): void
    {
        $linkChanged = $wholeRecord ? $model->getAttribute('github_url') !== null : $model->wasChanged('github_url');

        if ($linkChanged) {
            app(self::class)->sync();
        }
    }

    /**
     * @param  bool  $spread  spread first reads over the interval (the daily command) instead of reading now (a link was just added)
     * @return array{added: int, restored: int, removed: int}
     */
    public function sync(bool $spread = false): array
    {
        $linked = GithubRepo::linked();
        $existing = Repository::withTrashed()->get()->keyBy(fn (Repository $repository): string => strtolower($repository->url));
        $counts = ['added' => 0, 'restored' => 0, 'removed' => 0];

        foreach ($linked as $key => $repo) {
            $repository = $existing->get($key);

            if ($repository === null) {
                $repository = new Repository(['owner' => $repo->owner, 'name' => $repo->name, 'url' => $repo->url]);
                $repository->saveQuietly();
                $this->defaults->attach($repository, spread: $spread);
                $counts['added']++;
            } elseif ($repository->trashed()) {
                $repository->restoreQuietly();
                MonitorEffects::runSoon($repository);
                $counts['restored']++;
            }
        }

        foreach ($existing as $key => $repository) {
            if (! $repository->trashed() && ! $linked->has($key)) {
                $repository->deleteQuietly();
                $this->incidents->resolveFor($repository->monitors()->get(), IncidentResolution::ResourceDeleted);
                $counts['removed']++;
            }
        }

        return $counts;
    }
}
