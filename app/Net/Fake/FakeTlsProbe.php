<?php

namespace App\Net\Fake;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\CertificateInfo;
use App\Net\Results\TlsResult;
use App\Net\TlsProbe;
use Carbon\CarbonImmutable;

/** Canned TLS results by host. Unknown hosts can't be reached. */
class FakeTlsProbe implements TlsProbe
{
    /** @var array<string, TlsResult> */
    private array $results = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(TlsProbe::class, $fake);

        return $fake;
    }

    public function valid(string $host, string $issuer = "Let's Encrypt", string $expires = '+74 days'): self
    {
        $this->results[$host] = new TlsResult(true, null, new CertificateInfo(
            subject: $host,
            issuer: $issuer,
            sans: [$host],
            validFrom: CarbonImmutable::now()->subDays(16),
            expiresAt: CarbonImmutable::parse($expires),
            fingerprintSha256: hash('sha256', $host.$expires),
            serial: '0x01',
        ));

        return $this;
    }

    public function invalid(string $host, string $reason = 'The certificate has expired'): self
    {
        $this->results[$host] = new TlsResult(false, $reason, null);

        return $this;
    }

    public function inspect(string $host, int $port = 443, ?string $ip = null, float $timeout = 5): TlsResult
    {
        return $this->results[$host] ?? throw new CheckCouldNotRun("Couldn't connect to {$host}:{$port}");
    }
}
