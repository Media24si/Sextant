<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Site;
use Spatie\LaravelData\Data;

/**
 * A subdomain under its domain (Domains list and domain page, SPEC §13.6).
 * Expects `frontService` and `server` loaded.
 */
class DomainSiteData extends Data
{
    public function __construct(
        public int $id,
        public string $hostname,
        public ?string $description,
        public ?string $github_url,
        public Status $status,
        public ServingPathData $serving_path,
        public ?int $server_id,
    ) {}

    public static function fromModel(Site $site): self
    {
        return new self(
            id: $site->id,
            hostname: $site->display_hostname,
            description: $site->description,
            github_url: $site->github_url,
            status: $site->status,
            serving_path: $site->servingPath(),
            server_id: $site->server?->id,
        );
    }
}
