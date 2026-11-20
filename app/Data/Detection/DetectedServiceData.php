<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** An external service found in front of a site. */
class DetectedServiceData extends Data
{
    public function __construct(
        public int $service_id,
        public string $name,
    ) {}
}
