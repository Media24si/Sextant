<?php

namespace App\Data;

use App\Models\Site;
use App\Support\Hostname;
use Spatie\LaravelData\Data;

/** The values of the site form (SPEC §3.7, P1.15). */
class SiteFormData extends Data
{
    public function __construct(
        public ?int $id,
        public ?int $domain_id,
        public string $hostname,
        public string $scheme,
        public ?int $port,
        public ?int $front_service_id,
        public ?int $server_id,
        public ?string $description,
        public ?string $github_url,
        public ?string $notes,
    ) {}

    public static function fromModel(Site $site): self
    {
        return new self(
            id: $site->id,
            domain_id: $site->domain_id,
            hostname: Hostname::display($site->hostname),
            scheme: $site->scheme,
            port: $site->port,
            front_service_id: $site->front_service_id,
            server_id: $site->server_id,
            description: $site->description,
            github_url: $site->github_url,
            notes: $site->notes,
        );
    }
}
