<?php

namespace App\Net\Results;

use Carbon\CarbonImmutable;

/** Fields of a TLS certificate (SPEC §9.6, docs/spikes/tls.md). */
final readonly class CertificateInfo
{
    /**
     * @param  list<string>  $sans
     */
    public function __construct(
        public string $subject,
        public string $issuer,
        public array $sans,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $expiresAt,
        public string $fingerprintSha256,
        public string $serial,
    ) {}
}
