<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Observers\PackageMinimumObserver;
use App\Packages\PackageVersion;
use App\Versions\Floor;
use Carbon\CarbonInterface;
use Database\Factories\PackageMinimumFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The lowest safe version of a package, one per major version (SPEC §3.23,
 * §11.9), e.g. laravel/framework `12.13, 13.39`: 12.9 is below, 13.40 isn't,
 * 11.x is below (older than every listed major) and 14.x never is.
 *
 * @property int $id
 * @property Ecosystem $ecosystem
 * @property string $name
 * @property list<string> $min_versions
 * @property string|null $cve_url
 * @property string|null $note
 * @property int $set_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $setBy
 */
#[ObservedBy(PackageMinimumObserver::class)]
#[Fillable(['ecosystem', 'name', 'min_versions', 'cve_url', 'note'])]
class PackageMinimum extends Model
{
    /** @use HasFactory<PackageMinimumFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ecosystem' => Ecosystem::class, 'min_versions' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by')->withTrashed();
    }

    /** "laravel/framework (Composer)". */
    public function label(): string
    {
        return "{$this->name} ({$this->ecosystem->label()})";
    }

    /** "12.13, 13.39". */
    public function versionsList(): string
    {
        return implode(', ', $this->min_versions);
    }

    /** The listed version a package version is compared with (see Floor). */
    public function floorFor(string $version): ?string
    {
        return Floor::pick($this->min_versions, $version, PackageVersion::branch(...), PackageVersion::compare(...));
    }
}
