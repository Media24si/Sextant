<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\HasMonitors;
use App\Models\Concerns\Monitorable;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A GitHub repository that a site or application links to (SPEC §3.21).
 * Sextant reads its lock files with the owner's token (§11.9). Created and
 * removed by RepositorySync as links change, never by hand.
 *
 * @property int $id
 * @property string $owner
 * @property string $name
 * @property string $url
 * @property string|null $default_branch
 * @property list<string>|null $lock_files
 * @property Carbon|null $read_at
 * @property Status $status
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['owner', 'name', 'url'])]
class Repository extends Model implements Monitorable
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory, HasMonitors, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lock_files' => 'array',
            'read_at' => 'datetime',
            'status' => Status::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RepositoryPackage, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(RepositoryPackage::class)->orderBy('ecosystem')->orderBy('name');
    }

    /**
     * Applications whose GitHub link is this repository.
     *
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'github_url', 'url');
    }

    /**
     * Sites that link this repository themselves (not through their application).
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'github_url', 'url');
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }

    public function resourceName(): string
    {
        return $this->fullName();
    }
}
