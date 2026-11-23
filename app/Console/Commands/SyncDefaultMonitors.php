<?php

namespace App\Console\Commands;

use App\Checks\CheckerRegistry;
use App\Models\Domain;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\DefaultMonitors;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Adds one default monitor to existing resources that don't have it (SPEC
 * §4.4). `--checker` is required so monitors someone deleted on purpose
 * never come back by accident.
 */
#[Signature('monitors:sync-defaults {--checker= : Registry key, e.g. site.http} {--dry-run : Only list what would be added}')]
#[Description('Attach a default monitor to existing resources that lack it')]
class SyncDefaultMonitors extends Command
{
    public function handle(CheckerRegistry $registry, DefaultMonitors $defaults): int
    {
        $checker = (string) $this->option('checker');

        if ($checker === '' || ! $registry->has($checker)) {
            $this->components->error('Pass --checker with a registered key: '.implode(', ', array_keys($registry->entries())));

            return self::FAILURE;
        }

        $type = explode('.', $checker)[0];
        $model = ['server' => Server::class, 'domain' => Domain::class, 'site' => Site::class, 'repository' => Repository::class][$type];
        $dryRun = (bool) $this->option('dry-run');
        $added = 0;

        $model::query()->eachById(function (Server|Domain|Site|Repository $resource) use ($defaults, $checker, $dryRun, &$added): void {
            foreach ($defaults->attach($resource, spread: true, onlyChecker: $checker, dryRun: $dryRun) as $monitor) {
                $added++;
                $this->components->twoColumnDetail($resource->resourceName(), $monitor->label ?? $checker);
            }
        });

        $this->components->info(($dryRun ? 'Would add ' : 'Added ')."{$added} monitors.");

        return self::SUCCESS;
    }
}
