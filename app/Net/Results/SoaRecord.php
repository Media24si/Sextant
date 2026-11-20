<?php

namespace App\Net\Results;

final readonly class SoaRecord
{
    public function __construct(
        public int $serial,
        public string $primaryNameserver,
    ) {}
}
