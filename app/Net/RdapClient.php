<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\RdapDomain;

/** RDAP through the IANA bootstrap (SPEC §9.0, §9.3). */
interface RdapClient
{
    /**
     * Null when the TLD has no RDAP service (use WHOIS instead).
     *
     * @param  bool  $fresh  skip the 12-hour response cache
     *
     * @throws CheckCouldNotRun on rate limits and failed requests
     */
    public function domain(string $name, bool $fresh = false): ?RdapDomain;

    /**
     * Download the IANA bootstrap again (weekly, `rdap:refresh-bootstrap`).
     */
    public function refreshBootstrap(): void;
}
