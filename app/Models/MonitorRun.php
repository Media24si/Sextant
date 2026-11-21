<?php

namespace App\Models;

use App\Enums\Status;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of a monitor (SPEC §3.10). `data` is kept only for non-OK runs and
 * runs where a tracked field changed. Pruned after 90 days.
 *
 * @property int $id
 * @property int $monitor_id
 * @property Status $status
 * @property int $duration_ms
 * @property string|null $message
 * @property array<string, mixed>|null $data
 * @property CarbonInterface $checked_at
 */
class MonitorRun extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'duration_ms' => 'integer',
            'data' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('checked_at', '<', now()->subDays((int) config('sextant.runs.retention_days')));
    }
}
