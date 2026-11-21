<?php

namespace App\Monitoring;

use App\Net\DnsResolver;
use App\Net\TcpProbe;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Can Sextant reach the internet right now (SPEC §5.4)? Asked only when a
 * check fails; the answer is cached for 60 seconds under a lock.
 *
 * SPEC: §5.4 asks for a DNS lookup "through each configured resolver";
 * `DnsResolver` tries its resolvers in turn, so the lookup counts as one target.
 */
class NetworkHealth
{
    private const CACHE_KEY = 'network-health';

    public function __construct(
        private TcpProbe $tcp,
        private DnsResolver $dns,
    ) {}

    public function isHealthy(): bool
    {
        $seconds = (int) config('sextant.network_canary.cache_seconds');

        return (bool) Cache::lock(self::CACHE_KEY.':lock', 30)->block(30, fn (): bool => (bool) Cache::remember(
            self::CACHE_KEY,
            $seconds,
            fn (): bool => $this->probe(),
        ));
    }

    /** The cached answer, or null when nothing failed in the last minute. For the dashboard. */
    public function lastKnown(): ?bool
    {
        $value = Cache::get(self::CACHE_KEY);

        return $value === null ? null : (bool) $value;
    }

    private function probe(): bool
    {
        $timeout = (float) config('sextant.network_canary.timeout');
        $healthy = 0;

        foreach ((array) config('sextant.network_canary.tcp') as [$ip, $port]) {
            try {
                $healthy += $this->tcp->connect((string) $ip, (int) $port, $timeout)->ok ? 1 : 0;
            } catch (Throwable) {
                // Counts as a failed target.
            }
        }

        try {
            $healthy += $this->dns->resolve((string) config('sextant.network_canary.dns_probe_name'))->resolves() ? 1 : 0;
        } catch (Throwable) {
            // Counts as a failed target.
        }

        return $healthy >= (int) config('sextant.network_canary.min_healthy');
    }
}
