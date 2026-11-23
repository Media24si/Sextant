<?php

namespace App\Data\Repositories;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/**
 * The Packages section of an application or site page (SPEC §13.6): its
 * repository and the packages that have a minimum.
 */
class RepositorySummaryData extends Data
{
    public function __construct(
        public int $id,
        public string $full_name,
        public Status $status,
        public ?string $read_at,
        /** @var list<string> */
        public array $lock_files,
        public int $packages_count,
        /** @var list<RepositoryPackageData> packages with a minimum, below first */
        public array $watched,
        /** For a site without its own link: the application it inherits the repository from. */
        public ?string $via,
    ) {}
}
