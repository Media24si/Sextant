<?php

namespace App\Net\Results;

use Carbon\CarbonImmutable;

final readonly class GithubRepository
{
    public function __construct(
        public string $defaultBranch,
        public bool $archived,
        /** From the `github-authentication-token-expiration` header; null when the token doesn't expire. */
        public ?CarbonImmutable $tokenExpiresAt = null,
    ) {}
}
