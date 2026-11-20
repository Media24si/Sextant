<?php

namespace App\Net\Results;

final readonly class PingResult
{
    public function __construct(
        public float $lossPct,
        public ?float $rttAvgMs,
    ) {}
}
