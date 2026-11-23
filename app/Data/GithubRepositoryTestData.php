<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/** One repository in a GitHub token test: readable or why not, and the lock files found. */
class GithubRepositoryTestData extends Data
{
    public function __construct(
        /** "owner/name" */
        public string $name,
        public bool $ok,
        public ?string $error = null,
        public ?string $default_branch = null,
        /** @var list<string> */
        public array $lock_files = [],
    ) {}
}
