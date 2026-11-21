<?php

namespace App\Data\Dashboard;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/** A server, domain or site with its status and page (SPEC §13.7). */
class ResourceRefData extends Data
{
    public function __construct(
        /** server, domain or site */
        public string $type,
        public int $id,
        public string $name,
        public Status $status,
        public string $url,
    ) {}
}
