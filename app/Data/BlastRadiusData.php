<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * What goes down with a server or an external service: its sites, and the
 * applications and domains they belong to (whole or in part).
 */
class BlastRadiusData extends Data
{
    public function __construct(
        public int $sites,
        /** @var list<DependentData> */
        public array $applications,
        /** @var list<DependentData> */
        public array $domains,
        /** @var list<string> every hostname, sorted, for "Copy hostnames" */
        public array $hostnames,
    ) {}
}
