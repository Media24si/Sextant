<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/** The server, domain or site a monitor runs against. */
class MonitorResourceData extends Data
{
    public function __construct(
        /** server, domain or site */
        public string $type,
        public int $id,
        public string $name,
    ) {}
}
