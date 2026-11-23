<?php

namespace App\Observers;

use App\Models\Monitor;
use App\Models\PackageMinimum;
use App\Monitoring\MonitorClaimer;
use App\Packages\PackageMinimums;

/**
 * `package_minimum.set` / `changed` / `removed` (SPEC §8.4); every change
 * claims an immediate run of every enabled `repository.packages` monitor,
 * like a server software minimum does for versions checks (§6.4).
 */
class PackageMinimumObserver extends InventoryObserver
{
    public function created(PackageMinimum $minimum): void
    {
        $this->record('package_minimum.set', $minimum, "Minimum {$minimum->label()} set to {$minimum->versionsList()}", ['min_versions' => ['old' => null, 'new' => $minimum->min_versions]]);
        $this->runPackageChecks();
    }

    public function updated(PackageMinimum $minimum): void
    {
        $changes = $this->changes($minimum, ['min_versions', 'cve_url', 'note']);

        if ($changes === []) {
            return;
        }

        $summary = isset($changes['min_versions'])
            ? "Minimum {$minimum->label()} changed to {$minimum->versionsList()} (was ".implode(', ', (array) $changes['min_versions']['old']).')'
            : "Minimum {$minimum->label()} changed: ".implode(', ', array_keys($changes));

        $this->record('package_minimum.changed', $minimum, $summary, $changes);
        $this->runPackageChecks();
    }

    public function deleted(PackageMinimum $minimum): void
    {
        $this->record('package_minimum.removed', $minimum, "Minimum {$minimum->label()} removed (was {$minimum->versionsList()})", ['min_versions' => ['old' => $minimum->min_versions, 'new' => null]]);
        $this->runPackageChecks();
    }

    private function runPackageChecks(): void
    {
        app(PackageMinimums::class)->flush();
        $claimer = app(MonitorClaimer::class);

        Monitor::query()->where('checker', 'repository.packages')->where('enabled', true)->whereHasMorph('monitorable', '*')->get()
            ->each(fn (Monitor $monitor): bool => $claimer->claimNow($monitor));
    }
}
