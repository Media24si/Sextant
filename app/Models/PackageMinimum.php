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
 * The lowest safe versions of a package, one per release line (SPEC §3.23,
 * §11.9). A version's line is every part but the last: laravel/framework
 * `12.13, 13.39` sets one per major (12.9 is below, 13.40 isn't, 11.x is
 * below as older than every listed major, 14.x never is), and vite
 * `8.1.7, 8.2.5, 8.3.7` one per minor (8.2.3 is below, 8.4.0 isn't, 8.0.x is
 * below as older than every listed line of 8).
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

    /**
     * The listed version a package version is compared with. When some are on
     * the version's major, the one on the nearest line at or below it, or the
     * lowest of that major when the version is older than all of them (so
     * it's below); otherwise by major, as for server software (see Floor).
     */
    public function floorFor(string $version): ?string
    {
        $major = PackageVersion::branch($version);
        $sameMajor = array_values(array_filter(
            $this->min_versions,
            fn (string $minimum): bool => $major !== null && PackageVersion::branch($minimum) === $major,
        ));

        if ($sameMajor === []) {
            return Floor::pick($this->min_versions, $version, PackageVersion::branch(...), PackageVersion::compare(...));
        }

        usort($sameMajor, PackageVersion::compare(...));
        $floor = $sameMajor[0];

        foreach ($sameMajor as $minimum) {
            if (PackageVersion::isAtOrAboveLine($version, (string) PackageVersion::line($minimum))) {
                $floor = $minimum;
            }
        }

        return $floor;
    }
}
