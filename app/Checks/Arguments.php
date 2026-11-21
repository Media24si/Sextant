<?php

namespace App\Checks;

use InvalidArgumentException;

/**
 * A monitor's resolved arguments (SPEC §4.5): registry defaults with the
 * monitor's overrides on top, key by key. Asking for a key the checker's
 * schema doesn't have throws, which catches typos.
 */
final readonly class Arguments
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $names  the schema's argument names
     */
    public function __construct(
        private array $values,
        private array $names,
    ) {}

    /**
     * @param  list<Argument>  $schema
     * @param  array<string, mixed>  $defaults  the registry's `arguments`
     * @param  array<string, mixed>|null  $overrides  the monitor's `arguments`
     */
    public static function resolve(array $schema, array $defaults, ?array $overrides): self
    {
        $names = array_map(fn (Argument $argument): string => $argument->name, $schema);
        $values = [];

        foreach ($names as $name) {
            $values[$name] = array_key_exists($name, $overrides ?? []) ? $overrides[$name] : ($defaults[$name] ?? null);
        }

        return new self($values, $names);
    }

    public function int(string $key): int
    {
        return (int) $this->value($key);
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->value($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function string(string $key): string
    {
        return (string) $this->value($key);
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->value($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->value($key), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        return array_values((array) ($this->value($key) ?? []));
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        return array_map('intval', $this->list($key));
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        return array_map('strval', $this->list($key));
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    private function value(string $key): mixed
    {
        if (! in_array($key, $this->names, true)) {
            throw new InvalidArgumentException("Argument `{$key}` is not in this checker's schema.");
        }

        return $this->values[$key];
    }
}
