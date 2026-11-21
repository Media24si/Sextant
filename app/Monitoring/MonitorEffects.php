<?php

namespace App\Monitoring;

use App\Models\Concerns\Monitorable;
use Illuminate\Database\Eloquent\Model;

/** Runs that a change to a resource makes due right away (SPEC §6.4). */
class MonitorEffects
{
    /**
     * The resource's enabled monitors (or just one checker's) are due now.
     */
    public static function runSoon(Model&Monitorable $resource, ?string $checker = null): void
    {
        $resource->monitors()
            ->where('enabled', true)
            ->when($checker !== null, fn ($query) => $query->where('checker', $checker))
            ->toBase()
            ->update(['next_run_at' => now()->startOfSecond()]);
    }
}
