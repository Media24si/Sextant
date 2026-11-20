<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\PingProbe;
use App\Net\Results\PingResult;
use Illuminate\Support\Facades\Process;

/**
 * Linux iputils `ping -c {count} -w {timeout}` (SPEC §9.0, docs/spikes/icmp.md).
 */
class SystemPingProbe implements PingProbe
{
    public function ping(string $ip, int $count = 3, int $timeout = 5): PingResult
    {
        $command = ['ping', ...(str_contains($ip, ':') ? ['-6'] : []), '-c', (string) $count, '-w', (string) $timeout, $ip];
        $result = Process::timeout($timeout + 5)->run($command);
        $output = $result->output().$result->errorOutput();

        if (preg_match('/([\d.]+)% packet loss/', $output, $loss) !== 1) {
            throw new CheckCouldNotRun('Ping could not run: '.trim($output));
        }

        $rtt = preg_match('#= [\d.]+/([\d.]+)/#', $output, $match) === 1 ? (float) $match[1] : null;

        return new PingResult((float) $loss[1], $rtt);
    }
}
