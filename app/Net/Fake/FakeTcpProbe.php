<?php

namespace App\Net\Fake;

use App\Net\Results\TcpResult;
use App\Net\TcpProbe;

/** Open ports for tests, as "ip:port". Everything else is refused. */
class FakeTcpProbe implements TcpProbe
{
    /** @var array<string, true> */
    private array $open = [];

    /** @var array<string, true> */
    private array $timingOut = [];

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

    public function timingOut(string $ip, int $port): self
    {
        $this->timingOut["{$ip}:{$port}"] = true;

        return $this;
    }

    /** Every target of the network canary answers (SPEC §5.4). */
    public function openCanary(): self
    {
        foreach ((array) config('sextant.network_canary.tcp') as [$ip, $port]) {
            $this->open((string) $ip, (int) $port);
        }

        return $this;
    }

    public function connect(string $ip, int $port, float $timeout = 5): TcpResult
    {
        return match (true) {
            isset($this->open["{$ip}:{$port}"]) => new TcpResult(true, 12),
            isset($this->timingOut["{$ip}:{$port}"]) => new TcpResult(false, (int) ($timeout * 1000), 'Connection timed out'),
            default => new TcpResult(false, 1, 'Connection refused'),
        };
    }
}
