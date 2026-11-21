<?php

namespace App\Versions;

use App\Enums\Software;

/** One version from a versions file (SPEC §3.14). */
final readonly class SoftwareReading
{
    /**
     * @param  array<string, mixed>|null  $details  e.g. `{"cli": true, "fpm": true}` or the OS name
     */
    public function __construct(
        public Software $software,
        public string $branch,
        public string $version,
        public ?string $package = null,
        public ?array $details = null,
    ) {}

    public function key(): string
    {
        return "{$this->software->value}:{$this->branch}";
    }
}
