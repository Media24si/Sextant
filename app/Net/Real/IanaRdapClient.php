<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\RdapClient;
use App\Net\RdapParser;
use App\Net\Results\RdapDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * RDAP via the IANA bootstrap (SPEC §9.0). The bootstrap is cached and kept
 * on disk as a fallback; responses are cached for 12 hours unless `fresh`.
 */
class IanaRdapClient implements RdapClient
{
    public const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    private const BOOTSTRAP_FILE = 'rdap-bootstrap.json';

    public function domain(string $name, bool $fresh = false): ?RdapDomain
    {
        $tld = substr($name, (int) strrpos($name, '.') + 1);
        $base = $this->bootstrap()[$tld] ?? null;

        if ($base === null) {
            return null;
        }

        $server = (string) parse_url($base, PHP_URL_HOST);
        $key = "rdap.domain.{$name}";

        if ($fresh) {
            Cache::forget($key);
        }

        /** @var array{status: int, json: array<string, mixed>} $result */
        $result = Cache::remember($key, now()->addHours(12), fn (): array => $this->fetch($base.'domain/'.$name));

        return $result['status'] === 404
            ? new RdapDomain(found: false, server: $server)
            : RdapParser::parse($result['json'], $server);
    }

    public function refreshBootstrap(): void
    {
        try {
            $response = $this->http()->get(self::BOOTSTRAP_URL);
        } catch (ConnectionException $exception) {
            throw new CheckCouldNotRun('Could not download the RDAP bootstrap: '.$exception->getMessage(), previous: $exception);
        }

        $services = $this->servicesFrom($response->json());

        if ($response->failed() || $services === []) {
            throw new CheckCouldNotRun("Could not download the RDAP bootstrap (HTTP {$response->status()}).");
        }

        Storage::put(self::BOOTSTRAP_FILE, (string) json_encode($response->json()));
        Cache::forever('rdap.bootstrap', $services);
    }

    /**
     * TLD => base URL ending in a slash.
     *
     * @return array<string, string>
     */
    private function bootstrap(): array
    {
        /** @var array<string, string>|null $cached */
        $cached = Cache::get('rdap.bootstrap');

        if ($cached !== null) {
            return $cached;
        }

        if (Storage::exists(self::BOOTSTRAP_FILE)) {
            $services = $this->servicesFrom(json_decode((string) Storage::get(self::BOOTSTRAP_FILE), true));
            Cache::forever('rdap.bootstrap', $services);

            return $services;
        }

        $this->refreshBootstrap();

        return (array) Cache::get('rdap.bootstrap', []);
    }

    /**
     * @return array<string, string>
     */
    private function servicesFrom(mixed $json): array
    {
        $services = [];

        foreach ((array) (is_array($json) ? ($json['services'] ?? []) : []) as $service) {
            $base = $service[1][0] ?? null;

            if (! is_string($base)) {
                continue;
            }

            foreach ((array) ($service[0] ?? []) as $tld) {
                $services[strtolower((string) $tld)] = rtrim($base, '/').'/';
            }
        }

        return $services;
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     */
    private function fetch(string $url): array
    {
        try {
            $response = $this->http()->get($url);
        } catch (ConnectionException $exception) {
            throw new CheckCouldNotRun('RDAP request failed: '.$exception->getMessage(), previous: $exception);
        }

        return match (true) {
            $response->status() === 404 => ['status' => 404, 'json' => []],
            $response->status() === 429 => throw new CheckCouldNotRun('RDAP rate limit reached'),
            $response->successful() && is_array($response->json()) => ['status' => 200, 'json' => $response->json()],
            default => throw new CheckCouldNotRun("RDAP request failed (HTTP {$response->status()})"),
        };
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent((string) config('sextant.user_agent'))
            ->accept('application/rdap+json, application/json')
            ->timeout(10);
    }
}
