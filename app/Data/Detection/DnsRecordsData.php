<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/** Where a hostname points. */
class DnsRecordsData extends Data
{
    public function __construct(
        /** @var list<string> */
        public array $ips,
        /** @var list<string> */
        public array $cname_chain,
    ) {}
}
