<?php

namespace App\Observers;

use App\Activity\ActivityRecorder;
use App\Checks\CheckerRegistry;
use App\Enums\ActivityCategory;
use App\Enums\IncidentResolution;
use App\Models\Monitor;
use App\Monitoring\IncidentManager;

/**
 * Admin changes to monitors (SPEC §8.2); disabling or deleting one resolves
 * its open incident (§6.4). Entries are about the resource, with
 * the monitor id, so they show on its page. Default monitors and the runner
 * write quietly and never get here.
 */
class MonitorObserver extends InventoryObserver
{
    protected function excluded(): array
    {
        return ['next_run_at', 'monitorable_type', 'monitorable_id'];
    }

    public function created(Monitor $monitor): void
    {
        $this->recordFor($monitor, 'monitor.created', 'added');
    }

    public function updated(Monitor $monitor): void
    {
        if ($monitor->wasChanged('enabled')) {
            $this->recordFor($monitor, $monitor->enabled ? 'monitor.enabled' : 'monitor.disabled', $monitor->enabled ? 'enabled' : 'disabled');

            if (! $monitor->enabled) {
                app(IncidentManager::class)->resolveFor([$monitor], IncidentResolution::MonitorDisabled);
            }
        }

        if ($monitor->wasChanged('muted_until')) {
            $this->recordFor($monitor, $monitor->muted_until === null ? 'monitor.unmuted' : 'monitor.muted', $monitor->muted_until === null ? 'unmuted' : 'muted');
        }

        $changes = array_diff_key($this->changes($monitor), array_flip(['enabled', 'muted_until']));

        if ($changes !== []) {
            $this->recordFor($monitor, 'monitor.updated', 'changed: '.implode(', ', array_keys($changes)), $changes);
        }
    }

    public function deleted(Monitor $monitor): void
    {
        $this->recordFor($monitor, 'monitor.deleted', 'deleted');
        app(IncidentManager::class)->resolveFor([$monitor], IncidentResolution::MonitorDeleted);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordFor(Monitor $monitor, string $type, string $what, array $properties = []): void
    {
        $resource = $monitor->resource();

        if ($resource === null) {
            return;
        }

        $name = app(CheckerRegistry::class)->monitorName($monitor);

        ActivityRecorder::record(
            ActivityCategory::Inventory,
            $type,
            $resource,
            "{$resource->resourceName()}: {$name} monitor {$what}",
            $properties,
            monitorId: $monitor->id,
        );
    }
}
