<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Site;
use Spatie\LaravelData\Data;

/**
 * A row of the Sites list (SPEC §13.6). Expects `domain`, `application`,
 * `frontService` and `server` loaded.
 */
class SiteRowData extends Data
{
    public function __construct(
        public int $id,
        public string $hostname,
        public ?string $description,
        public int $domain_id,
        public string $domain,
        public ?int $application_id,
        public ?string $application,
        public ServingPathData $serving_path,
        public ?int $server_id,
        public Status $status,
        public bool $deleted,
    ) {}

    public static function fromModel(Site $site): self
    {
        return new self(
            id: $site->id,
            hostname: $site->display_hostname,
            description: $site->description,
            domain_id: $site->domain_id,
            domain: $site->domain->display_name,
            application_id: $site->application_id,
            application: $site->application?->name,
            serving_path: $site->servingPath(),
            server_id: $site->server?->id,
            status: $site->status,
            deleted: $site->trashed(),
        );
    }
}
