<?php

namespace App\Net;

use App\Net\Results\HttpResult;

/** An HTTP GET that records redirects and timings (SPEC §9.0, §9.5). */
interface HttpProbe
{
    /**
     * @param  int  $maxBytes  body bytes to keep; 0 = don't read the body
     */
    public function get(string $url, bool $followRedirects = true, int $maxBytes = 0, float $timeout = 10, bool $verifyTls = true): HttpResult;
}
