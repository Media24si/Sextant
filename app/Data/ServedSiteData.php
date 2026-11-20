<?php

namespace App\Data;

use App\Enums\Status;
use App\Models\Site;
use Spatie\LaravelData\Data;

/**
 * A site in a server's "Sites served" list. Expects `frontService` and
 * `server` loaded.
 */
class ServedSiteData extends Data
{
    public function __construct(
        public int $id,
        public string $hostname,
        public ?string $description,
        public Status $status,
        public ServingPathData $serving_path,
    ) {}

    public static function fromModel(Site $site): self
    {
        return new self($site->id, $site->display_hostname, $site->description, $site->status, $site->servingPath());
    }
}
