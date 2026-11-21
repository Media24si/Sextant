<?php

namespace App\Models\Concerns;

use App\Models\Monitor;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @see Monitorable
 */
trait HasMonitors
{
    /**
     * @return MorphMany<Monitor, $this>
     */
    public function monitors(): MorphMany
    {
        return $this->morphMany(Monitor::class, 'monitorable');
    }
}
