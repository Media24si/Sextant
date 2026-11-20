<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** One of our servers found as a site's backend. */
class DetectedServerData extends Data
{
    public function __construct(
        public int $server_id,
        public string $name,
    ) {}
}
