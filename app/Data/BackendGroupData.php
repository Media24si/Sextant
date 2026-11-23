<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One box of "Where traffic through {service} goes": a backend server and
 * the sites behind the service that it builds. `server_id` null = sites
 * without a backend.
 */
class BackendGroupData extends Data
{
    public function __construct(
        public ?int $server_id,
        public ?string $server,
        public int $sites_count,
        public string $first_site,
    ) {}
}
