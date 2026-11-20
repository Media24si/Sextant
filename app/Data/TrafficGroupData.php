<?php

namespace App\Data;

use App\Enums\ExternalServiceKind;
use Spatie\LaravelData\Data;

/**
 * One box of "How traffic reaches {server}": an external service in front of
 * some of its sites, or the sites whose DNS points straight at it.
 */
class TrafficGroupData extends Data
{
    public function __construct(
        public string $name,
        /** null = direct */
        public ?ExternalServiceKind $kind,
        public int $sites_count,
        public string $first_site,
    ) {}
}
