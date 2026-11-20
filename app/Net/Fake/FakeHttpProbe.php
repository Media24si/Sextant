<?php

namespace App\Net\Fake;

use App\Net\HttpProbe;
use App\Net\Results\HttpResult;

/** Canned HTTP responses for tests, by exact URL. Unknown URLs fail to connect. */
class FakeHttpProbe implements HttpProbe
{
    /** @var array<string, HttpResult> */
    private array $responses = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(HttpProbe::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function respond(string $url, int $status = 200, array $headers = [], int $ms = 120, ?string $redirectsTo = null, ?string $ip = null): self
    {
        $chain = [['url' => $url, 'status' => $redirectsTo === null ? $status : 301]];

        if ($redirectsTo !== null) {
            $chain[] = ['url' => $redirectsTo, 'status' => $status];
        }

        $this->responses[$url] = new HttpResult(
            chain: $chain,
            finalUrl: $redirectsTo ?? $url,
            status: $status,
            headers: array_change_key_case($headers),
            timings: ['total' => $ms, 'dns' => 5, 'connect' => 10, 'tls' => 20, 'ttfb' => $ms - 10],
            ip: $ip,
        );

        return $this;
    }

    public function fail(string $url, string $error = 'Connection refused'): self
    {
        $this->responses[$url] = new HttpResult([], null, null, error: $error);

        return $this;
    }

    public function get(string $url, bool $followRedirects = true, int $maxBytes = 0, float $timeout = 10, bool $verifyTls = true): HttpResult
    {
        return $this->responses[$url] ?? new HttpResult([], null, null, error: 'Connection refused');
    }
}
