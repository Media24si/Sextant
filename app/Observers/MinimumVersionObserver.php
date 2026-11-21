<?php

namespace App\Observers;

use App\Models\MinimumVersion;
use App\Models\Monitor;
use App\Monitoring\MonitorClaimer;
use App\Versions\MinimumVersions;

/**
 * `minimum_version.set` / `changed` / `removed` (SPEC §8.4); every change
 * claims an immediate run of every enabled `server.versions` monitor (§6.4).
 */
class MinimumVersionObserver extends InventoryObserver
{
    public function created(MinimumVersion $minimum): void
    {
        $this->record('minimum_version.set', $minimum, "Minimum {$minimum->label()} set to {$minimum->min_version}", ['min_version' => ['old' => null, 'new' => $minimum->min_version]]);
        $this->runVersionChecks();
    }

    public function updated(MinimumVersion $minimum): void
    {
        $changes = $this->changes($minimum, ['min_version', 'cve_url', 'note']);

        if ($changes === []) {
            return;
        }

        $summary = isset($changes['min_version'])
            ? "Minimum {$minimum->label()} changed to {$minimum->min_version} (was {$changes['min_version']['old']})"
            : "Minimum {$minimum->label()} changed: ".implode(', ', array_keys($changes));

        $this->record('minimum_version.changed', $minimum, $summary, $changes);
        $this->runVersionChecks();
    }

    public function deleted(MinimumVersion $minimum): void
    {
        $this->record('minimum_version.removed', $minimum, "Minimum {$minimum->label()} removed (was {$minimum->min_version})", ['min_version' => ['old' => $minimum->min_version, 'new' => null]]);
        $this->runVersionChecks();
    }

    private function runVersionChecks(): void
    {
        app(MinimumVersions::class)->flush();
        $claimer = app(MonitorClaimer::class);

        Monitor::query()->where('checker', 'server.versions')->where('enabled', true)->whereHasMorph('monitorable', '*')->get()
            ->each(fn (Monitor $monitor): bool => $claimer->claimNow($monitor));
    }
}
