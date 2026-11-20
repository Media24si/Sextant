<?php

namespace App\Models;

use App\Enums\Status;
use App\Support\Hostname;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
#[Fillable(['name', 'hostname', 'provider', 'os_choice_id', 'notes', 'versions_url'])]
#[Hidden(['versions_token'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, SoftDeletes;

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
}
