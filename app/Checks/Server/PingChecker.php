<?php

namespace App\Checks\Server;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\ServerChecker;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Net\PingProbe;

/**
 * `server.ping` (SPEC §9.2): packet loss and round trip to the server.
 * Capped at warning; `server.tcp` is the authoritative reachability check.
 */
class PingChecker extends ServerChecker
{
    public function __construct(private PingProbe $ping) {}

    public static function name(): string
    {
        return 'Ping';
    }

    public static function arguments(): array
    {
        return [
            Argument::int('count', 'Packets')->rules(['between:1,10']),
            Argument::int('timeout', 'Timeout (seconds)')->rules(['between:1,30']),
        ];
    }

    /**
     * The primary address, or the first IPv4 one when the primary is IPv6.
     *
     * SPEC: the Sextant server can't ping over IPv6 (docs/spikes/icmp.md).
     */
    public static function addressOf(Server $server): ?string
    {
        $server->loadMissing('addresses');
        $primary = $server->addresses->firstWhere('is_primary', true);

        if ($primary instanceof ServerAddress && $primary->version === 4) {
            return $primary->ip;
        }

        return $server->addresses->firstWhere('version', 4)?->ip;
    }

    protected function check(Server $server, Arguments $arguments): CheckResult
    {
        $address = self::addressOf($server)
            ?? throw new CheckCouldNotRun("{$server->name} has no IPv4 address to ping (Sextant can't ping over IPv6)");

        $result = $this->ping->ping($address, $arguments->int('count'), $arguments->int('timeout'));
        $loss = (int) round($result->lossPct);
        $data = ['address' => $address, 'loss_pct' => $result->lossPct, 'rtt_avg_ms' => $result->rttAvgMs];

        if ($loss === 0) {
            return CheckResult::ok('0% loss'.($result->rttAvgMs !== null ? ', '.round($result->rttAvgMs).' ms' : ''), $data);
        }

        return CheckResult::warning("{$loss}% packet loss", $data);
    }
}
