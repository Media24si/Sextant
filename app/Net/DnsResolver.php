<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\DnsAnswer;
use App\Net\Results\SoaRecord;

/**
 * DNS through Sextant's own resolvers (SPEC §9.0, docs/spikes/net-dns2.md).
 * NXDOMAIN is an answer; anything that stops the lookup itself throws
 * CheckCouldNotRun.
 */
interface DnsResolver
{
    /**
     * @throws CheckCouldNotRun
     */
    public function resolve(string $host): DnsAnswer;

    /**
     * @return list<string> lowercase, no trailing dots; empty when there are none
     *
     * @throws CheckCouldNotRun
     */
    public function ns(string $domain): array;

    /**
     * Asks one nameserver directly (no recursion).
     *
     * @throws CheckCouldNotRun
     */
    public function soa(string $domain, string $nameserver): ?SoaRecord;
}
