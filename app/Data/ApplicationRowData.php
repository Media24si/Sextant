<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Application;
use App\Models\Site;
use Spatie\LaravelData\Data;

/**
 * An application in the Applications list and on its page (SPEC §13.6).
 * Expects `sites` loaded.
 */
class ApplicationRowData extends Data
{
    /**
     * @param  list<string>  $hostnames
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public ?string $github_url,
        public int $sites_count,
        public array $hostnames,
        public Status $status,
        public bool $deleted,
    ) {}

    public static function fromModel(Application $application): self
    {
        $sites = $application->sites->sortBy('hostname');

        return new self(
            id: $application->id,
            name: $application->name,
            description: $application->description,
            github_url: $application->github_url,
            sites_count: $sites->count(),
            hostnames: array_values($sites->map(fn (Site $site): string => $site->display_hostname)->all()),
            status: $application->status(),
            deleted: $application->trashed(),
        );
    }
}
