<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * A site's serving path (SPEC §3.7): an optional external service in front,
 * then one of our servers as the backend. Null backend = not assigned.
 */
class ServingPathData extends Data
{
    public function __construct(
        public ?string $front,
        public ?string $backend,
    ) {}
}
