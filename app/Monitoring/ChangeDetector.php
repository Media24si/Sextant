<?php

namespace App\Monitoring;

use App\Activity\ActivityRecorder;
use App\Checks\Checker;
use App\Enums\ActivityCategory;
use App\Models\Concerns\Monitorable;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Model;

/**
 * Records `detected` changes of a checker's tracked fields (SPEC §8.3): only
 * when the previous value wasn't null, so the first run records nothing.
 */
class ChangeDetector
{
    /**
     * @param  class-string<Checker>  $checker
     * @param  array<string, mixed>|null  $previous  the state's data before this run
     * @param  array<string, mixed>  $current
     * @return bool whether a tracked field changed
     */
    public function detect(Monitor $monitor, Model&Monitorable $resource, string $checker, ?array $previous, array $current): bool
    {
        $changed = false;

        foreach ($checker::trackedFields() as $field => $type) {
            $old = $previous[$field] ?? null;
            $new = $current[$field] ?? null;

            if ($old === null || $this->same($old, $new)) {
                continue;
            }

            $changed = true;

            ActivityRecorder::record(
                ActivityCategory::Detected,
                $type,
                $resource,
                "{$resource->resourceName()}: ".$checker::describeChange($field, $old, $new),
                ['field' => $field, 'old' => $old, 'new' => $new],
                userId: null,
                monitorId: $monitor->id,
            );
        }

        return $changed;
    }

    /** Lists compare as sets, so a reordered answer isn't a change. */
    private function same(mixed $old, mixed $new): bool
    {
        if (is_array($old) && is_array($new) && array_is_list($old) && array_is_list($new)) {
            sort($old);
            sort($new);
        }

        return $old == $new;
    }
}
