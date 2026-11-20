<?php

namespace App\Net;

use App\Net\Results\TcpResult;

/** A plain TCP connect (SPEC §9.0, §9.1). */
interface TcpProbe
{
    public function connect(string $ip, int $port, float $timeout = 5): TcpResult;
}
