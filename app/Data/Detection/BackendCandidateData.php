<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** A server the backend might be, with how many sibling sites use it (SPEC §10.3). */
class BackendCandidateData extends Data
{
    public function __construct(
        public int $server_id,
        public string $name,
        public int $sibling_count,
    ) {}
}
