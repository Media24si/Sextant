<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/** A certificate or domain expiring within 60 days (SPEC §13.7). */
class ExpiringItemData extends Data
{
    public function __construct(
        /** certificate or domain */
        public string $kind,
        public string $name,
        /** Y-m-d, Europe/Ljubljana */
        public string $expires_at,
        public int $days,
        public string $url,
    ) {}
}
