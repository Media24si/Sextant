<?php

namespace App\Net\Real;

use App\Net\HttpProbe;
use App\Net\Results\HttpResult;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GET through Laravel's HTTP client, one hop at a time so every redirect is
 * recorded (up to 5, SPEC §9.5). Uses config('sextant.user_agent').
 */
class GuzzleHttpProbe implements HttpProbe
{
    private const MAX_REDIRECTS = 5;

    public function get(string $url, bool $followRedirects = true, int $maxBytes = 0, float $timeout = 10, bool $verifyTls = true): HttpResult
    {
        $chain = [];
        $timings = [];
        $totalMs = 0;
        $ip = null;
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $stats = null;
            // Only the first `$maxBytes` of a body are kept; pages can be large.
            $sink = new CappedSink(max(0, $maxBytes));

            try {
                $response = Http::withUserAgent((string) config('sextant.user_agent'))
                    ->withoutRedirecting()
                    ->timeout((int) ceil($timeout))
                    ->withOptions([
                        'verify' => $verifyTls,
                        'sink' => $sink,
                        'on_stats' => function (TransferStats $transfer) use (&$stats): void {
                            $stats = $transfer->getHandlerStats();
                        },
                    ])
                    ->get($current);
            } catch (ConnectionException $exception) {
                return new HttpResult($chain, $chain === [] ? null : $current, null, [], $timings, $ip, $this->describe($exception));
            } catch (Throwable $exception) {
                return new HttpResult($chain, $chain === [] ? null : $current, null, [], $timings, $ip, $exception->getMessage());
            }

            if (is_array($stats)) {
                $ip = is_string($stats['primary_ip'] ?? null) && $stats['primary_ip'] !== '' ? $stats['primary_ip'] : $ip;
                $timings = $this->timings($stats);
                $totalMs += $timings['total'];
                // The whole visit, redirects included; the other timings are the final hop's.
                $timings['total'] = $totalMs;
            }

            $chain[] = ['url' => $current, 'status' => $response->status()];
            $location = $response->header('Location');

            if (! $followRedirects || ! $response->redirect() || $location === '') {
                $headers = [];

                foreach ($response->headers() as $name => $values) {
                    $headers[strtolower($name)] = implode(', ', $values);
                }

                return new HttpResult(
                    chain: $chain,
                    finalUrl: $current,
                    status: $response->status(),
                    headers: $headers,
                    timings: $timings,
                    ip: $ip,
                    body: $maxBytes > 0 ? $response->body() : null,
                );
            }

            $current = $this->resolve($current, $location);
        }

        return new HttpResult($chain, $current, null, [], $timings, $ip, 'Too many redirects');
    }

    /**
     * @param  array<string, mixed>  $stats  curl handler stats, seconds
     * @return array<string, int>
     */
    private function timings(array $stats): array
    {
        $ms = fn (string $key): int => (int) round(((float) ($stats[$key] ?? 0)) * 1000);

        return [
            'total' => $ms('total_time'),
            'dns' => $ms('namelookup_time'),
            'connect' => $ms('connect_time'),
            'tls' => max(0, $ms('appconnect_time') - $ms('connect_time')),
            'ttfb' => $ms('starttransfer_time'),
        ];
    }

    private function describe(ConnectionException $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'Could not resolve host') => 'DNS lookup failed',
            str_contains($message, 'timed out'), str_contains($message, 'Timeout') => 'Timed out',
            str_contains($message, 'Connection refused') => 'Connection refused',
            str_contains($message, 'SSL'), str_contains($message, 'certificate') => 'TLS failed: '.$message,
            default => $message,
        };
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$directory.'/'.$location;
    }
}
