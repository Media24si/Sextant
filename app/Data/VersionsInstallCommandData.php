<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The one-line installer for a server (SPEC §11.8): a signed link that works
 * until `expires_at`, and the masked token it installs.
 */
class VersionsInstallCommandData extends Data
{
    public function __construct(
        public string $command,
        public string $expires_at,
        public string $token_masked,
    ) {}
}
