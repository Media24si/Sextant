<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Site;
use Spatie\LaravelData\Data;

/**
 * A site in a server's "Sites served" list. Expects `frontService`,
 * `server` and `application` loaded.
 */
class ServedSiteData extends Data
{
    public function __construct(
        public int $id,
        public string $hostname,
        public ?string $description,
        public ?int $application_id,
        public ?string $application,
        public Status $status,
        public ServingPathData $serving_path,
    ) {}

    public static function fromModel(Site $site): self
    {
        return new self(
            id: $site->id,
            hostname: $site->display_hostname,
            description: $site->description,
            application_id: $site->application_id,
            application: $site->application?->name,
            status: $site->status,
            serving_path: $site->servingPath(),
        );
    }
}
