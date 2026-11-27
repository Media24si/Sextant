<?php

namespace App\Checks;

use App\Checks\Conditions\Condition;
use App\Enums\Status;
use App\Models\CheckerSetting;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reads `config/checkers.php` (SPEC §4.3) with the defaults set on the Monitor
 * defaults page on top, and resolves a monitor's settings (§4.5): config ←
 * stored checker defaults ← monitor overrides. The only place that knows
 * checker classes.
 *
 * @phpstan-type Entry array{class: class-string<Checker>, queue: string, interval: int, failures_before_incident: int, successes_before_resolve: int, max_severity?: string, requires_network?: bool, arguments: array<string, mixed>}
 * @phpstan-type DefaultEntry array{checker: string, label?: string|null, arguments?: array<string, mixed>, only_if?: class-string<Condition>}
 */
class CheckerRegistry
{
    /** Base class each key prefix requires. */
    private const BASES = [
        ServerChecker::PREFIX => ServerChecker::class,
        DomainChecker::PREFIX => DomainChecker::class,
        SiteChecker::PREFIX => SiteChecker::class,
        RepositoryChecker::PREFIX => RepositoryChecker::class,
    ];

    /**
     * Config entries with the stored defaults merged in.
     *
     * @return array<string, Entry>
     */
    public function entries(): array
    {
        $entries = $this->configEntries();
        $stored = CheckerSetting::cached();

        foreach ($entries as $key => $entry) {
            if (isset($stored[$key]) && class_exists($entry['class'])) {
                $entries[$key] = $this->withStored($entry, $stored[$key]);
            }
        }

        return $entries;
    }

    /**
     * The entries as `config/checkers.php` has them, without stored defaults.
     *
     * @return array<string, Entry>
     */
    public function configEntries(): array
    {
        /** @var array<string, Entry> $entries */
        $entries = (array) config('checkers.checkers', []);

        return $entries;
    }

    /**
     * @return Entry
     *
     * @throws UnknownChecker
     */
    public function configEntry(string $key): array
    {
        return $this->configEntries()[$key] ?? throw UnknownChecker::key($key);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries());
    }

    /**
     * @return Entry
     *
     * @throws UnknownChecker
     */
    public function entry(string $key): array
    {
        return $this->entries()[$key] ?? throw UnknownChecker::key($key);
    }

    /**
     * @return class-string<Checker>
     */
    public function checkerClass(string $key): string
    {
        return $this->entry($key)['class'];
    }

    public function checker(string $key): Checker
    {
        return app($this->checkerClass($key));
    }

    /** Human name of a key, e.g. "HTTP"; the key itself when it's not registered. */
    public function name(string $key): string
    {
        return $this->has($key) && class_exists($this->checkerClass($key)) ? $this->checkerClass($key)::name() : $key;
    }

    /** "HTTP", or the label when there is one: "SSH". */
    public function monitorName(Monitor $monitor): string
    {
        return $monitor->label ?? $this->name($monitor->checker);
    }

    public function interval(Monitor $monitor): int
    {
        return $monitor->interval_minutes ?? $this->entry($monitor->checker)['interval'];
    }

    public function queue(Monitor $monitor): string
    {
        return $this->entry($monitor->checker)['queue'];
    }

    /**
     * Registry entry ← monitor overrides (§4.5).
     */
    public function settings(Monitor $monitor): ResolvedSettings
    {
        $entry = $this->entry($monitor->checker);

        return new ResolvedSettings(
            arguments: Arguments::resolve($entry['class']::arguments(), $entry['arguments'], $monitor->arguments),
            interval: $monitor->interval_minutes ?? $entry['interval'],
            failuresBeforeIncident: $monitor->failures_before_incident ?? $entry['failures_before_incident'],
            successesBeforeResolve: $monitor->successes_before_resolve ?? $entry['successes_before_resolve'],
            maxSeverity: Status::from($entry['max_severity'] ?? Status::Error->value),
            requiresNetwork: $entry['requires_network'] ?? true,
            queue: $entry['queue'],
        );
    }

    /**
     * The checker's schema arguments a stored row sets replace the config
     * ones; stale keys are ignored. Null thresholds keep the config value.
     *
     * @param  Entry  $entry
     * @param  array{arguments: array<string, mixed>, interval_minutes: int|null, failures_before_incident: int|null, successes_before_resolve: int|null}  $stored
     * @return Entry
     */
    private function withStored(array $entry, array $stored): array
    {
        foreach ($entry['class']::arguments() as $argument) {
            if (array_key_exists($argument->name, $stored['arguments']) && ! $argument->isPerResource()) {
                $entry['arguments'][$argument->name] = $stored['arguments'][$argument->name];
            }
        }

        $entry['interval'] = $stored['interval_minutes'] ?? $entry['interval'];
        $entry['failures_before_incident'] = $stored['failures_before_incident'] ?? $entry['failures_before_incident'];
        $entry['successes_before_resolve'] = $stored['successes_before_resolve'] ?? $entry['successes_before_resolve'];

        return $entry;
    }

    /**
     * Default monitors for a resource type (§4.4), registered checkers only.
     *
     * @param  'server'|'domain'|'site'|'repository'  $type
     * @return list<DefaultEntry>
     */
    public function defaults(string $type): array
    {
        /** @var list<DefaultEntry> $defaults */
        $defaults = (array) config("checkers.defaults.{$type}", []);

        return array_values(array_filter($defaults, fn (array $default): bool => $this->has($default['checker'])));
    }

    /**
     * Keys a resource type can have, e.g. `site.http` for a site.
     *
     * @return list<string>
     */
    public function keysFor(string $type): array
    {
        return array_values(array_filter(array_keys($this->entries()), fn (string $key): bool => str_starts_with($key, "{$type}.")));
    }

    /**
     * The argument schema as JSON, with the registry defaults (§4.6).
     *
     * @return list<array{type: string, name: string, label: string, help: string|null, nullable: bool, options: array<string, string>|null, default: mixed}>
     */
    public function schema(string $key, ?Model $resource = null): array
    {
        $entry = $this->entry($key);

        return array_map(
            fn (Argument $argument): array => $argument->toArray($resource, $entry['arguments'][$argument->name] ?? null),
            $entry['class']::arguments(),
        );
    }

    /**
     * Problems with the registry, for `sextant:checkers` and tests (§4.3, §17).
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        /** @var array<string, array<string, mixed>> $raw */
        $raw = (array) config('checkers.checkers', []);

        foreach ($raw as $key => $entry) {
            [$prefix] = explode('.', $key, 2) + [1 => ''];
            $class = $entry['class'] ?? null;

            if (! is_string($class) || ! class_exists($class)) {
                $errors[] = "{$key}: class ".(is_string($class) ? $class : '(none)').' does not exist';

                continue;
            }

            if (! isset(self::BASES[$prefix])) {
                $errors[] = "{$key}: the key must start with server., domain., site. or repository.";
            } elseif (! is_subclass_of($class, self::BASES[$prefix])) {
                $errors[] = "{$key}: {$class} must extend ".class_basename(self::BASES[$prefix]);
            }

            if (! in_array($entry['queue'] ?? null, ['checks', 'checks-slow'], true)) {
                $errors[] = "{$key}: queue must be checks or checks-slow";
            }

            foreach (['interval', 'failures_before_incident', 'successes_before_resolve'] as $field) {
                if (! is_int($entry[$field] ?? null) || $entry[$field] < 1) {
                    $errors[] = "{$key}: {$field} must be a positive integer";
                }
            }

            if (isset($entry['max_severity']) && ! in_array($entry['max_severity'], ['warning', 'error'], true)) {
                $errors[] = "{$key}: max_severity must be warning or error";
            }

            try {
                $schema = array_map(fn (Argument $argument): string => $argument->name, $class::arguments());
            } catch (Throwable $exception) {
                $errors[] = "{$key}: arguments() failed: {$exception->getMessage()}";

                continue;
            }

            $defaults = array_keys((array) ($entry['arguments'] ?? []));

            foreach (array_diff($schema, $defaults) as $missing) {
                $errors[] = "{$key}: no default for argument `{$missing}`";
            }

            foreach (array_diff($defaults, $schema) as $extra) {
                $errors[] = "{$key}: default `{$extra}` is not in the schema";
            }
        }

        foreach ((array) config('checkers.defaults', []) as $type => $defaults) {
            foreach ((array) $defaults as $index => $default) {
                $checker = is_array($default) ? ($default['checker'] ?? null) : null;

                if (! is_string($checker) || ! $this->has($checker)) {
                    $errors[] = "defaults.{$type}.{$index}: checker ".(is_string($checker) ? $checker : '(none)').' is not registered';
                } elseif (! str_starts_with($checker, "{$type}.")) {
                    $errors[] = "defaults.{$type}.{$index}: {$checker} doesn't check a {$type}";
                }

                $condition = is_array($default) ? ($default['only_if'] ?? null) : null;

                if ($condition !== null && (! is_string($condition) || ! is_subclass_of($condition, Condition::class))) {
                    $errors[] = "defaults.{$type}.{$index}: only_if must be a Condition class";
                }
            }
        }

        return $errors;
    }
}
