<?php

namespace App\Packages;

use App\Enums\Ecosystem;

/** One locked package version read from a lock file (SPEC §11.9). */
final readonly class PackageReading
{
    public function __construct(
        public Ecosystem $ecosystem,
        public string $name,
        public string $version,
        /** Only needed for development (`packages-dev`, npm `dev: true`). */
        public bool $dev,
        /** Required by the project itself rather than by another package. */
        public bool $direct,
    ) {}

    public function key(): string
    {
        return "{$this->ecosystem->value}:{$this->name}@{$this->version}";
    }

    /**
     * One reading per package version: a version is dev only when every
     * occurrence is, and direct when any is.
     *
     * @param  iterable<self>  $readings
     * @return list<self>
     */
    public static function merge(iterable $readings): array
    {
        $merged = [];

        foreach ($readings as $reading) {
            $existing = $merged[$reading->key()] ?? null;

            $merged[$reading->key()] = $existing === null ? $reading : new self(
                $reading->ecosystem,
                $reading->name,
                $reading->version,
                $existing->dev && $reading->dev,
                $existing->direct || $reading->direct,
            );
        }

        return array_values($merged);
    }
}
