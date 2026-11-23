<?php

namespace App\Models;

use App\Enums\Status;
use App\Observers\ApplicationObserver;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The web application behind one or more sites, e.g. one backend answering on
 * backend.svet24.si and backend.reporter.si (SPEC §3.19). It groups sites; each
 * hostname keeps its own domain and checks. Sextant doesn't check it directly.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $github_url
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Repository|null $repository
 */
#[ObservedBy(ApplicationObserver::class)]
#[Fillable(['name', 'description', 'github_url', 'notes'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * The repository of the GitHub link (SPEC §3.21).
     *
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'github_url', 'url');
    }

    /**
     * The worst status of its live sites. Expects `sites` loaded.
     */
    public function status(): Status
    {
        return Status::worst(...$this->sites->map(fn (Site $site): Status => $site->status)->all());
    }

    public function resourceName(): string
    {
        return $this->name;
    }
}
