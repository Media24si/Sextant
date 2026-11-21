<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Site;
use Spatie\LaravelData\Data;

/**
 * A site page (SPEC §13.6). Expects `domain`, `application`, `frontService` and `server` loaded.
 */
class SiteData extends Data
{
    public function __construct(
        public int $id,
        public string $hostname,
        public string $url,
        public ?string $description,
        public ?string $github_url,
        public ?string $notes,
        public int $domain_id,
        public string $domain,
        public ?int $application_id,
        public ?string $application,
        public ServingPathData $serving_path,
        public ?int $front_service_id,
        public ?int $server_id,
        public Status $status,
        public bool $deleted,
    ) {}

    public static function fromModel(Site $site): self
    {
        return new self(
            id: $site->id,
            hostname: $site->display_hostname,
            url: $site->url,
            description: $site->description,
            github_url: $site->github_url,
            notes: $site->notes,
            domain_id: $site->domain_id,
            domain: $site->domain->display_name,
            application_id: $site->application_id,
            application: $site->application?->name,
            serving_path: $site->servingPath(),
            front_service_id: $site->frontService?->id,
            server_id: $site->server?->id,
            status: $site->status,
            deleted: $site->trashed(),
        );
    }
}
