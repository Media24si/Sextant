<?php

namespace App\Data\Repositories;

use Spatie\LaravelData\Data;

/** An application or site whose code is in a repository (SPEC §3.21). */
class RepositoryUserData extends Data
{
    public function __construct(
        /** application or site */
        public string $type,
        public int $id,
        public string $name,
        /** For a site without its own link: the application it inherits the repository from. */
        public ?string $via = null,
    ) {}
}
