<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\CertificateInfo;
use App\Net\Results\TlsResult;
use App\Net\TlsProbe;
use Carbon\CarbonImmutable;
use Spatie\SslCertificate\SslCertificate;
use Throwable;

/**
 * A verifying handshake; on failure, an unverified download says why
 * (docs/spikes/tls.md). Code 0 from the socket = verification failed,
 * anything else = no connection.
 */
class StreamTlsProbe implements TlsProbe
{
    public function inspect(string $host, int $port = 443, ?string $ip = null, float $timeout = 5): TlsResult
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
        ]]);

        $target = $ip === null ? $host : (str_contains($ip, ':') ? "[{$ip}]" : $ip);
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $socket = stream_socket_client("ssl://{$target}:{$port}", $code, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }

        $warning = implode(' ', $warnings);

        // Code 0 also covers DNS failures; only a failed handshake is the certificate's fault.
        if ($socket === false && ($code !== 0 || ! str_contains($warning, 'Failed to enable crypto'))) {
            throw new CheckCouldNotRun("Couldn't connect to {$host}:{$port}: ".($message !== '' ? $message : $warning));
        }

        if ($socket !== false) {
            $peer = stream_socket_get_name($socket, true);
            $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            fclose($socket);

            return new TlsResult(true, null, $this->certificate($certificate), $this->ip($peer));
        }

        return $this->unverified($host, $port, $ip, $timeout, $warning);
    }

    private function unverified(string $host, int $port, ?string $ip, float $timeout, string $warning): TlsResult
    {
        set_error_handler(fn (): bool => true);

        try {
            $download = SslCertificate::download()
                ->withVerifyPeer(false)
                ->withVerifyPeerName(false)
                ->usingPort($port)
                ->setTimeout((int) ceil($timeout));

            if ($ip !== null) {
                $download->fromIpAddress($ip);
            }

            $certificate = $download->forHost($host);
        } catch (Throwable) {
            $certificate = false;
        } finally {
            restore_error_handler();
        }

        if (! $certificate instanceof SslCertificate) {
            return new TlsResult(false, $this->reason($warning, null, $host), null, $ip);
        }

        return new TlsResult(false, $this->reason($warning, $certificate, $host), $this->info($certificate), $ip);
    }

    private function reason(string $warning, ?SslCertificate $certificate, string $host): string
    {
        return match (true) {
            str_contains($warning, 'did not match expected name') => "The certificate doesn't cover {$host}",
            $certificate?->isExpired() === true => 'The certificate has expired',
            $certificate !== null && ! $certificate->appliesToUrl("https://{$host}") => "The certificate doesn't cover {$host}",
            $certificate?->isSelfSigned() === true => 'The certificate is self-signed',
            default => 'The certificate chain isn\'t trusted',
        };
    }

    private function certificate(mixed $resource): ?CertificateInfo
    {
        if ($resource === null || ! openssl_x509_export($resource, $pem)) {
            return null;
        }

        return $this->info(SslCertificate::createFromString($pem));
    }

    private function info(SslCertificate $certificate): CertificateInfo
    {
        return new CertificateInfo(
            subject: $certificate->getDomain(),
            issuer: $certificate->getOrganization() !== '' ? $certificate->getOrganization() : $certificate->getIssuer(),
            sans: array_values($certificate->getAdditionalDomains()),
            validFrom: CarbonImmutable::instance($certificate->validFromDate()),
            expiresAt: CarbonImmutable::instance($certificate->expirationDate()),
            fingerprintSha256: $certificate->getFingerprintSha256(),
            serial: $certificate->getSerialNumber(),
        );
    }

    private function ip(string|false $peer): ?string
    {
        if ($peer === false) {
            return null;
        }

        return trim(preg_replace('/:\d+$/', '', $peer) ?? $peer, '[]');
    }
}
