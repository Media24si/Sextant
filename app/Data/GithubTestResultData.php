<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** "Test" on the GitHub access page (SPEC §11.9): which linked repositories the token can read. */
class GithubTestResultData extends Data
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?string $expires_at = null,
        /** @var list<GithubRepositoryTestData> */
        public array $repositories = [],
    ) {}
}
