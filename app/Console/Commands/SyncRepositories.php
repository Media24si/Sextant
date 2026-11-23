<?php

namespace App\Console\Commands;

use App\Packages\RepositorySync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily, and once after deploying: one repository per GitHub link of a live
 * site or application, with its packages monitor (SPEC §3.21, §14). Saving
 * a link syncs at once; this catches anything that slipped past.
 */
#[Signature('repositories:sync')]
#[Description('Create, restore or remove repositories to match the GitHub links of sites and applications')]
class SyncRepositories extends Command
{
    public function handle(RepositorySync $sync): int
    {
        $counts = $sync->sync(spread: true);

        foreach ($counts as $what => $count) {
            $this->components->twoColumnDetail(ucfirst($what), (string) $count);
        }

        return self::SUCCESS;
    }
}
