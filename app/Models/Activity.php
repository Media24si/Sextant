<?php

namespace App\Models;

use App\Enums\ActivityCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the change feed (SPEC §3.13). Written only through
 * ActivityRecorder::record().
 *
 * @property int $id
 * @property ActivityCategory $category
 * @property string $type
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $server_id
 * @property int|null $monitor_id
 * @property int|null $incident_id
 * @property int|null $user_id
 * @property string $summary
 * @property array<string, mixed>|null $properties
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Model|null $subject
 */
class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ActivityCategory::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Who did it; null means Sextant itself.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
