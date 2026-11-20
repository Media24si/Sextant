<?php

namespace App\Net\Fake;

use App\Net\Results\TcpResult;
use App\Net\TcpProbe;

/** Open ports for tests, as "ip:port". Everything else is refused. */
class FakeTcpProbe implements TcpProbe
{
    /** @var array<string, true> */
    private array $open = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(TcpProbe::class, $fake);

        return $fake;
    }

    public function open(string $ip, int $port): self
    {
        $this->open["{$ip}:{$port}"] = true;

        return $this;
    }

    public function connect(string $ip, int $port, float $timeout = 5): TcpResult
    {
        return isset($this->open["{$ip}:{$port}"])
            ? new TcpResult(true, 12)
            : new TcpResult(false, 1, 'Connection refused');
    }
}
