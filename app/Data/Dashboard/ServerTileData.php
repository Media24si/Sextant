<?php

namespace App\Data\Dashboard;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/** A server on the Overview's map: its status and how many sites it builds. */
class ServerTileData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public Status $status,
        public int $sites_count,
        public bool $below_minimum,
    ) {}
}
