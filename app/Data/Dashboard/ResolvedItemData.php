<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/** A root incident resolved in the last 24 hours (SPEC §13.7). */
class ResolvedItemData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public string $opened_at,
        public string $resolved_at,
        public int $children_count,
        public string $url,
    ) {}
}
