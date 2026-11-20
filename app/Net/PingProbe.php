<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\PingResult;

/** The system ping (SPEC §9.0, §9.2). */
interface PingProbe
{
    /**
     * @throws CheckCouldNotRun when ping can't run here (e.g. no IPv6)
     */
    public function ping(string $ip, int $count = 3, int $timeout = 5): PingResult;
}
