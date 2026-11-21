<?php

namespace App\Models\Concerns;

use App\Enums\Status;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A server, domain or site: something monitors run against (SPEC §3.8, §7).
 *
 * @property Status $status
 * @property Carbon|null $status_changed_at
 */
interface Monitorable
{
    /**
     * @return MorphMany<Monitor, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function monitors(): MorphMany;

    /** How messages and the change feed name it, e.g. `web02` or `podjetje.svet24.si`. */
    public function resourceName(): string;
}
