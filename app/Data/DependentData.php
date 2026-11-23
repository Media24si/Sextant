<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * An application or domain in a blast radius: how many of its live sites
 * depend on the server or service, out of all of them.
 */
class DependentData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $affected,
        public int $total,
    ) {}
}
