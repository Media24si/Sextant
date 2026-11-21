<?php

namespace App\Models;

use App\Enums\Software;
use App\Observers\MinimumVersionObserver;
use Carbon\CarbonInterface;
use Database\Factories\MinimumVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The lowest safe upstream version of a piece of software, or of one PHP
 * branch (SPEC §3.15, §11.6).
 *
 * @property int $id
 * @property Software $software
 * @property string $branch
 * @property string $min_version
 * @property string|null $cve_url
 * @property string|null $note
 * @property int $set_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $setBy
 */
#[ObservedBy(MinimumVersionObserver::class)]
#[Fillable(['software', 'branch', 'min_version', 'cve_url', 'note'])]
class MinimumVersion extends Model
{
    /** @use HasFactory<MinimumVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['software' => Software::class];
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
        return $this->software->labelWithBranch($this->branch);
    }
}
