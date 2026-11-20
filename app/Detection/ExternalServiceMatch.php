<?php

namespace App\Detection;

use App\Models\ExternalService;

final readonly class ExternalServiceMatch
{
    public function __construct(
        public ExternalService $service,
        /** For the finding's explanation, e.g. "203.0.113.80 belongs to the external service Varnish." */
        public string $reason,
    ) {}
}
