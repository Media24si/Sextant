<?php

namespace App\Models;

use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use Carbon\CarbonInterface;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A monitor's failure from open to resolved (SPEC §3.11, §6.3). Written only
 * by `IncidentManager`. Resolved if `resolved_at` is set, acknowledged if
 * `acknowledged_at` is set, otherwise open.
 *
 * @property int $id
 * @property int $monitor_id
 * @property string $monitorable_type
 * @property int $monitorable_id
 * @property int|null $server_id
 * @property int|null $parent_id
 * @property string $checker
 * @property string $title
 * @property Status $severity
 * @property Status $max_severity
 * @property string $message
 * @property CarbonInterface $opened_at
 * @property CarbonInterface $last_seen_at
 * @property CarbonInterface|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property CarbonInterface|null $resolved_at
 * @property IncidentResolution|null $resolution
 * @property int $occurrences
 * @property array{status: string, message: string, data: array<string, mixed>} $first_result
 * @property array{status: string, message: string, data: array<string, mixed>} $last_result
 * @property CarbonInterface|null $last_notified_at
 * @property int|null $open_monitor_id
 * @property-read Monitor $monitor
 * @property-read (Model&Monitorable)|null $monitorable
 * @property-read Incident|null $parent
 * @property-read User|null $acknowledgedBy
 */
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => Status::class,
            'max_severity' => Status::class,
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolution' => IncidentResolution::class,
            'occurrences' => 'integer',
            'first_result' => 'array',
            'last_result' => 'array',
            'last_notified_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Incident>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * @param  Builder<Incident>  $query
     */
    public function scopeResolved(Builder $query): void
    {
        $query->whereNotNull('resolved_at');
    }

    /**
     * Not grouped under an open server outage (§6.5).
     *
     * SPEC: a child keeps `parent_id` after its parent resolves; once the
     * parent is resolved, the child counts as a root again so it isn't hidden.
     *
     * @param  Builder<Incident>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('parent_id')
            ->orWhereHas('parent', fn (Builder $parent) => $parent->whereNotNull('resolved_at')));
    }

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function monitorable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'parent_id');
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Incident::class, 'parent_id');
    }

    /**
     * @return HasMany<IncidentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by')->withTrashed();
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
