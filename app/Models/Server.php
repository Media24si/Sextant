<?php

namespace App\Models;

use App\Activity\ActivityRecorder;
use App\Enums\ActivityCategory;
use App\Enums\Status;
use App\Models\Concerns\HasMonitors;
use App\Models\Concerns\Monitorable;
use App\Observers\ServerObserver;
use App\Support\Hostname;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One of our own servers (SPEC §3.2). Servers have no role field.
 *
 * @property int $id
 * @property string $name
 * @property string|null $hostname
 * @property string|null $provider
 * @property int|null $os_choice_id
 * @property string|null $os_reported
 * @property string|null $notes
 * @property string|null $versions_url
 * @property string|null $versions_token
 * @property string|null $versions_token_last4
 * @property Status $status
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Choice|null $os
 * @property-read ServerAddress|null $primaryAddress
 */
#[ObservedBy(ServerObserver::class)]
#[Fillable(['name', 'hostname', 'provider', 'os_choice_id', 'notes', 'versions_url'])]
#[Hidden(['versions_token'])]
class Server extends Model implements Monitorable
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasMonitors, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'versions_token' => 'encrypted',
            'status' => Status::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function hostname(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null || trim($value) === '' ? null : Hostname::normalize($value));
    }

    /**
     * @return HasMany<ServerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ServerAddress::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * The address checks target unless told otherwise (SPEC §3.3).
     *
     * @return HasOne<ServerAddress, $this>
     */
    public function primaryAddress(): HasOne
    {
        return $this->hasOne(ServerAddress::class)->where('is_primary', true);
    }

    /**
     * @return BelongsTo<Choice, $this>
     */
    public function os(): BelongsTo
    {
        return $this->belongsTo(Choice::class, 'os_choice_id');
    }

    /**
     * @return BelongsToMany<Choice, $this>
     */
    public function backupLocations(): BelongsToMany
    {
        return $this->belongsToMany(Choice::class, 'server_backup_location')->orderBy('position');
    }

    /**
     * Sites whose backend this server is (SPEC §3.7).
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * Set where the backups are stored and record the change
     * (`server.backup_locations_changed`, SPEC §8.4).
     *
     * SPEC: §8.2 puts an observer on the pivot; pivot rows don't fire model
     * events on sync(), so the change is recorded here instead.
     *
     * @param  list<int>  $choiceIds
     */
    public function syncBackupLocations(array $choiceIds): void
    {
        $before = $this->backupLocations()->pluck('label')->all();
        $changes = $this->backupLocations()->sync($choiceIds);

        if ($changes['attached'] === [] && $changes['detached'] === []) {
            return;
        }

        $after = $this->backupLocations()->pluck('label')->all();

        ActivityRecorder::record(
            ActivityCategory::Inventory,
            'server.backup_locations_changed',
            $this,
            "{$this->name} backups: ".($after === [] ? 'none' : implode(', ', $after)).' (was '.($before === [] ? 'none' : implode(', ', $before)).')',
            ['old' => $before, 'new' => $after],
        );
    }

    public function resourceName(): string
    {
        return $this->name;
    }
}
