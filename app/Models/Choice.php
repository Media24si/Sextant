<?php

namespace App\Models;

use App\Enums\ChoiceList;
use Database\Factories\ChoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An entry in an admin-editable list: an operating system or a backup
 * location (SPEC §3.4).
 *
 * @property int $id
 * @property ChoiceList $list
 * @property string $label
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['list', 'label', 'position'])]
class Choice extends Model
{
    /** @use HasFactory<ChoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list' => ChoiceList::class,
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofList(Builder $query, ChoiceList $list): void
    {
        $query->where('list', $list)->orderBy('position');
    }

    /**
     * Servers whose operating system this is.
     *
     * @return HasMany<Server, $this>
     */
    public function osServers(): HasMany
    {
        return $this->hasMany(Server::class, 'os_choice_id');
    }

    /**
     * Servers whose backups are stored here.
     *
     * @return BelongsToMany<Server, $this>
     */
    public function backupServers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_backup_location');
    }

    /**
     * A choice in use can be renamed but not deleted (SPEC §3.4).
     */
    public function isInUse(): bool
    {
        return $this->osServers()->withTrashed()->exists() || $this->backupServers()->withTrashed()->exists();
    }
}
