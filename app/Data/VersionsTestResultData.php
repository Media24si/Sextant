<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** "Test connection" (SPEC §11.4): what the file holds, or why reading it failed. */
class VersionsTestResultData extends Data
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?string $generated_at = null,
        public ?string $hostname = null,
        /** @var list<VersionsTestReadingData> */
        public array $readings = [],
    ) {}

    public static function failed(string $error): self
    {
        return new self(ok: false, error: $error);
    }
}
