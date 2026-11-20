<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;

/** WHOIS on port 43, for TLDs without RDAP (SPEC §9.0, §9.3). */
interface WhoisClient
{
    /**
     * The raw response.
     *
     * @throws CheckCouldNotRun
     */
    public function query(string $domain): string;
}
