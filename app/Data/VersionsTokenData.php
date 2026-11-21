<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** A newly generated versions token, shown once (SPEC §11.2). */
class VersionsTokenData extends Data
{
    public function __construct(
        public string $token,
        public string $last4,
    ) {}
}
