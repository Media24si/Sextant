<?php

namespace App\Monitoring;

use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource's status is the worst effective status of its enabled, unmuted
 * monitors; Unknown when it has none (SPEC §7). Written quietly.
 */
class ResourceStatus
{
    public static function refresh(Model&Monitorable $resource): Status
    {
        $statuses = $resource->monitors()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('muted_until')->orWhere('muted_until', '<=', now()))
            ->with('state')
            ->get()
            ->map(fn (Monitor $monitor): Status => $monitor->state->status ?? Status::Unknown)
            ->all();

        $status = Status::worst(...$statuses);

        if ($resource->getAttribute('status') !== $status) {
            $resource->forceFill(['status' => $status, 'status_changed_at' => now()])->saveQuietly();
        }

        return $status;
    }
}
