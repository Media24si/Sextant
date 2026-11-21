<?php

namespace App\Models;

use App\Models\Concerns\Monitorable;
use App\Observers\MonitorObserver;
use Carbon\CarbonInterface;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A configured checker on one server, domain or site (SPEC §3.8, §4.1).
 * `arguments` and the thresholds hold overrides only; the registry fills in
 * the rest (§4.5).
 *
 * @property int $id
 * @property string $monitorable_type
 * @property int $monitorable_id
 * @property string $checker
 * @property string|null $label
 * @property array<string, mixed>|null $arguments
 * @property int|null $interval_minutes
 * @property int|null $failures_before_incident
 * @property int|null $successes_before_resolve
 * @property bool $enabled
 * @property CarbonInterface|null $muted_until
 * @property CarbonInterface|null $next_run_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read (Model&Monitorable)|null $monitorable
 * @property-read MonitorState|null $state
 */
#[ObservedBy(MonitorObserver::class)]
#[Fillable(['checker', 'label', 'arguments', 'interval_minutes', 'failures_before_incident', 'successes_before_resolve', 'enabled', 'muted_until', 'next_run_at'])]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // Every monitor has exactly one state row (§3.9).
        static::created(function (Monitor $monitor): void {
            MonitorState::query()->insertOrIgnore(['monitor_id' => $monitor->id, 'status' => 'unknown']);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'interval_minutes' => 'integer',
            'failures_before_incident' => 'integer',
            'successes_before_resolve' => 'integer',
            'enabled' => 'boolean',
            'muted_until' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function monitorable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<MonitorState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(MonitorState::class);
    }

    /**
     * @return HasMany<MonitorRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(MonitorRun::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * @return HasOne<Incident, $this>
     */
    public function openIncident(): HasOne
    {
        return $this->hasOne(Incident::class)->whereNull('resolved_at');
    }

    public function isMuted(): bool
    {
        return $this->muted_until?->isFuture() === true;
    }

    /** The resource this monitor runs against, deleted ones included. */
    public function resource(): (Model&Monitorable)|null
    {
        /** @var (Model&Monitorable)|null $resource */
        $resource = $this->monitorable()->withTrashed()->first();

        return $resource;
    }
}
