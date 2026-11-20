<?php

namespace App\Data;

use App\Enums\ActivityCategory;
use App\Models\Activity;
use Spatie\LaravelData\Data;

/**
 * A change-feed entry (SPEC §13.7 `ActivityItem`). Expects `user` loaded.
 */
class ActivityItemData extends Data
{
    public function __construct(
        public int $id,
        public ActivityCategory $category,
        public string $type,
        public string $summary,
        public ?string $user,
        public string $created_at,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        return new self(
            id: $activity->id,
            category: $activity->category,
            type: $activity->type,
            summary: $activity->summary,
            user: $activity->user?->name,
            created_at: $activity->created_at->toAtomString(),
        );
    }
}
