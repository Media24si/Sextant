<?php

namespace App\Net\Results;

/**
 * A TLS handshake (SPEC §9.0 `TlsProbe::inspect`). When verification failed,
 * `verifyError` says why and `certificate` comes from an unverified download.
 */
final readonly class TlsResult
{
    public function __construct(
        public bool $verified,
        public ?string $verifyError,
        public ?CertificateInfo $certificate,
        public ?string $ip = null,
    ) {}
}
