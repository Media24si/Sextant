<?php

namespace App\Activity;

use App\Enums\ActivityCategory;
use App\Models\Activity;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The only way entries reach the change feed (SPEC §8.1).
 */
class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $properties  `old`/`new` values and extra detail; never secrets
     * @param  int|null|false  $userId  who did it; false = the signed-in user for inventory changes, null for Sextant's own
     */
    public static function record(
        ActivityCategory $category,
        string $type,
        Model $subject,
        string $summary,
        array $properties = [],
        int|null|false $userId = false,
        ?int $monitorId = null,
        ?int $incidentId = null,
    ): Activity {
        if ($userId === false) {
            $userId = $category === ActivityCategory::Inventory ? Auth::id() : null;
        }

        return Activity::query()->create([
            'category' => $category,
            'type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'server_id' => self::serverIdFor($subject),
            'monitor_id' => $monitorId,
            'incident_id' => $incidentId,
            'user_id' => $userId,
            'summary' => mb_strimwidth($summary, 0, 500, '…'),
            'properties' => $properties === [] ? null : $properties,
            'created_at' => now(),
        ]);
    }

    /**
     * Same rule as incidents (SPEC §3.11, §3.13): the server itself, or the
     * site's backend server at the time.
     */
    private static function serverIdFor(Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Server => $subject->id,
            $subject instanceof Site => $subject->server_id,
            default => null,
        };
    }
}
