<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/** One software branch against its minimum version (SPEC §13.7). Filled in by Phase 7. */
class SoftwareMinimumRowData extends Data
{
    public function __construct(
        public string $software,
        public string $branch,
        public ?string $min_version,
        /** @var list<array{server: string, version: string}> */
        public array $below,
    ) {}
}
