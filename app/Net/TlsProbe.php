<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\TlsResult;

/** A verifying TLS connection plus the certificate (SPEC §9.0, §9.6). */
interface TlsProbe
{
    /**
     * @throws CheckCouldNotRun when no connection could be made at all
     */
    public function inspect(string $host, int $port = 443, ?string $ip = null, float $timeout = 5): TlsResult;
}
