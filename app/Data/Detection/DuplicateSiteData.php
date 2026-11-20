<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** The site that already has the hostname (SPEC §10.1 step 2). */
class DuplicateSiteData extends Data
{
    public function __construct(
        public int $site_id,
        public string $serving_path,
        public bool $trashed,
    ) {}
}
