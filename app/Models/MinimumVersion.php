<?php

namespace App\Models;

use App\Enums\Software;
use App\Observers\MinimumVersionObserver;
use App\Versions\Floor;
use App\Versions\VersionComparator;
use Carbon\CarbonInterface;
use Database\Factories\MinimumVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The lowest safe upstream versions of a piece of software, one per branch
 * (SPEC §3.15, §11.6), e.g. PHP `8.2.36, 8.3.37, 8.4.28`.
 *
 * @property int $id
 * @property Software $software
 * @property list<string> $min_versions
 * @property string|null $cve_url
 * @property string|null $note
 * @property int $set_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $setBy
 */
#[ObservedBy(MinimumVersionObserver::class)]
#[Fillable(['software', 'min_versions', 'cve_url', 'note'])]
class MinimumVersion extends Model
{
    /** @use HasFactory<MinimumVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['software' => Software::class, 'min_versions' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by')->withTrashed();
    }

    public function label(): string
    {
        return $this->software->label();
    }

    /** "8.2.36, 8.3.37". */
    public function versionsList(): string
    {
        return implode(', ', $this->min_versions);
    }

    /**
     * The listed version a reading is compared with: the one on the nearest
     * listed branch at or below the reading's branch, or the lowest one when
     * the reading's branch is older than every listed branch (so it's below).
     */
    public function floorFor(string $version): ?string
    {
        return Floor::pick(
            $this->min_versions,
            $version,
            fn (string $version): ?string => VersionComparator::branch($this->software, $version),
            fn (string $a, string $b): int => VersionComparator::compare($this->software, $a, $b),
        );
    }
}
