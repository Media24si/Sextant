<?php

namespace App\Models;

use App\Enums\Status;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monitor's current state (SPEC §3.9). `status` is the effective status
 * (§6.1); `last_result_status` is the raw status of the latest run.
 * Written only by the runner and the sweep.
 *
 * @property int $monitor_id
 * @property Status $status
 * @property CarbonInterface|null $status_changed_at
 * @property Status|null $last_result_status
 * @property string|null $message
 * @property array<string, mixed>|null $data
 * @property int|null $duration_ms
 * @property CarbonInterface|null $checked_at
 * @property CarbonInterface|null $last_definitive_at
 * @property CarbonInterface|null $stale_at
 * @property int $consecutive_failures
 * @property int $consecutive_successes
 * @property int $consecutive_unknowns
 * @property-read Monitor $monitor
 */
class MonitorState extends Model
{
    protected $primaryKey = 'monitor_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'status_changed_at' => 'datetime',
            'last_result_status' => Status::class,
            'data' => 'array',
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
            'last_definitive_at' => 'datetime',
            'stale_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'consecutive_unknowns' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** The latest run failed, but no incident is open for it (yet) (§6.1). */
    public function isPending(): bool
    {
        return $this->last_result_status?->isFailure() === true && ! $this->status->isFailure();
    }
}
