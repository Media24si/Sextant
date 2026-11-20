<?php

namespace App\Net\Results;

/**
 * An HTTP GET with its redirects (SPEC §9.0 `HttpProbe::get`). `error` is
 * set when no response came back (DNS, connect, TLS or timeout failure).
 */
final readonly class HttpResult
{
    /**
     * @param  list<array{url: string, status: int}>  $chain  every hop, the final response last
     * @param  array<string, string>  $headers  final response, lowercase names
     * @param  array<string, int>  $timings  milliseconds: total, dns, connect, tls, ttfb
     */
    public function __construct(
        public array $chain,
        public ?string $finalUrl,
        public ?int $status,
        public array $headers = [],
        public array $timings = [],
        public ?string $ip = null,
        public ?string $error = null,
        public ?string $body = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->status !== null;
    }

    public function redirected(): bool
    {
        return count($this->chain) > 1;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
