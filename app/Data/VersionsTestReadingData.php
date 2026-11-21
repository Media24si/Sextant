<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** One reading in a Test connection result. */
class VersionsTestReadingData extends Data
{
    public function __construct(
        public string $software,
        public string $version,
        public ?string $package,
    ) {}
}
