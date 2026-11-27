<?php

namespace App\Models;

use Database\Factories\CheckerSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Defaults for every monitor of one checker, set on the Monitor defaults
 * page. They sit between `config/checkers.php` and a monitor's own overrides;
 * a null column or a missing argument keeps the config value.
 *
 * @property int $id
 * @property string $checker
 * @property array<string, mixed>|null $arguments
 * @property int|null $interval_minutes
 * @property int|null $failures_before_incident
 * @property int|null $successes_before_resolve
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['checker', 'arguments', 'interval_minutes', 'failures_before_incident', 'successes_before_resolve'])]
class CheckerSetting extends Model
{
    /** @use HasFactory<CheckerSettingFactory> */
    use HasFactory;

    /** Cache key of every row, keyed by checker; read by `CheckerRegistry`. */
    public const CACHE_KEY = 'checker-settings';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'interval_minutes' => 'integer',
            'failures_before_incident' => 'integer',
            'successes_before_resolve' => 'integer',
        ];
    }

    /**
     * Every row as plain arrays, keyed by checker, cached until one changes.
     *
     * @return array<string, array{arguments: array<string, mixed>, interval_minutes: int|null, failures_before_incident: int|null, successes_before_resolve: int|null}>
     */
    public static function cached(): array
    {
        /** @var array<string, array{arguments: array<string, mixed>, interval_minutes: int|null, failures_before_incident: int|null, successes_before_resolve: int|null}> */
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => self::query()->get()->mapWithKeys(fn (self $setting): array => [
            $setting->checker => [
                'arguments' => $setting->arguments ?? [],
                'interval_minutes' => $setting->interval_minutes,
                'failures_before_incident' => $setting->failures_before_incident,
                'successes_before_resolve' => $setting->successes_before_resolve,
            ],
        ])->all());
    }
}
