<?php

namespace App\Net\Fake;

use App\Net\PingProbe;
use App\Net\Results\PingResult;

/** Addresses that answer ping, for tests. Everything else loses every packet. */
class FakePingProbe implements PingProbe
{
    /** @var array<string, true> */
    private array $answering = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(PingProbe::class, $fake);

        return $fake;
    }

    public function answers(string $ip): self
    {
        $this->answering[$ip] = true;

        return $this;
    }

    public function ping(string $ip, int $count = 3, int $timeout = 5): PingResult
    {
        return isset($this->answering[$ip]) ? new PingResult(0.0, 9.0) : new PingResult(100.0, null);
    }
}
