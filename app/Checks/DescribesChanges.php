<?php

namespace App\Checks;

/** The generic change summary (§8.3); checkers override it for nicer wording. */
trait DescribesChanges
{
    public static function describeChange(string $field, mixed $old, mixed $new): string
    {
        return str_replace('_', ' ', $field).' changed from '.self::plainValue($old).' to '.self::plainValue($new);
    }

    protected static function plainValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'nothing',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => $value === [] ? 'nothing' : implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item), $value)),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
