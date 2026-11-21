<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/** How many resources are in each status (SPEC §13.7). */
class StatusCountsData extends Data
{
    public function __construct(
        public int $total,
        public int $ok,
        public int $warning,
        public int $error,
        public int $unknown,
    ) {}
}
