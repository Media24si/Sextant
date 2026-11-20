<?php

namespace App\Observers;

use App\Activity\ActivityRecorder;
use App\Enums\ActivityCategory;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared behaviour of the inventory observers (SPEC §8.2): admin changes are
 * recorded with old and new values, leaving out timestamps, system-maintained
 * columns and secrets. Sextant's own writes use saveQuietly() and never get here.
 */
abstract class InventoryObserver
{
    /** Never recorded, on any model. */
    private const ALWAYS_EXCLUDED = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'status', 'status_changed_at', 'active_name', 'active_hostname',
    ];

    /**
     * Extra columns this model never records: system-maintained ones and secrets.
     *
     * @return list<string>
     */
    protected function excluded(): array
    {
        return [];
    }

    /**
     * The changed attributes of a model being updated, as `{old, new}` pairs.
     *
     * @param  list<string>  $only  limit to these attributes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    protected function changes(Model $model, array $only = []): array
    {
        $changes = [];

        foreach (array_keys($model->getChanges()) as $attribute) {
            if (in_array($attribute, [...self::ALWAYS_EXCLUDED, ...$this->excluded()], true)) {
                continue;
            }

            if ($only !== [] && ! in_array($attribute, $only, true)) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $this->plain($model->getOriginal($attribute)),
                'new' => $this->plain($model->getAttribute($attribute)),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function record(string $type, Model $subject, string $summary, array $properties = []): void
    {
        ActivityRecorder::record(ActivityCategory::Inventory, $type, $subject, $summary, $properties);
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        };
    }
}
