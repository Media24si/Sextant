<?php

namespace App\Checks\Site;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckResult;
use App\Checks\SiteChecker;
use App\Models\Site;
use App\Net\TlsProbe;
use Carbon\CarbonImmutable;

/**
 * `site.ssl`: is the certificate valid and far from expiry (SPEC §9.6)?
 * Behind a front service it's the edge certificate. Not being able to connect
 * at all is `site.http`'s job, so it's Unknown here.
 */
class SslCertificateChecker extends SiteChecker
{
    public function __construct(private TlsProbe $tls) {}

    public static function name(): string
    {
        return 'SSL certificate';
    }

    public static function arguments(): array
    {
        return [
            Argument::int('warning_days', 'Warn this many days before expiry')->rules(['between:1,365']),
            Argument::int('error_days', 'Error this many days before expiry')->rules(['between:0,365']),
            Argument::int('port', 'Port')->rules(['between:1,65535']),
        ];
    }

    public static function trackedFields(): array
    {
        return [
            'fingerprint_sha256' => 'certificate.renewed',
            'issuer' => 'certificate.issuer_changed',
        ];
    }

    public static function describeChange(string $field, mixed $old, mixed $new): string
    {
        return $field === 'issuer'
            ? 'certificate issuer changed from '.self::plainValue($old).' to '.self::plainValue($new)
            : 'certificate renewed';
    }

    protected function check(Site $site, Arguments $arguments): CheckResult
    {
        $result = $this->tls->inspect($site->hostname, $arguments->int('port'));
        $certificate = $result->certificate;
        $edge = $site->frontService !== null ? " (edge certificate, {$site->frontService->name})" : '';

        $timezone = (string) config('sextant.display_timezone');
        $days = $certificate === null ? null : (int) CarbonImmutable::now($timezone)->startOfDay()
            ->diffInDays($certificate->expiresAt->setTimezone($timezone)->startOfDay(), false);

        $data = [
            'subject' => $certificate?->subject,
            'issuer' => $certificate?->issuer,
            'sans' => $certificate?->sans,
            'valid_from' => $certificate?->validFrom->toIso8601String(),
            'expires_at' => $certificate?->expiresAt->toIso8601String(),
            'days_remaining' => $days,
            'fingerprint_sha256' => $certificate?->fingerprintSha256,
            'serial' => $certificate?->serial,
            'chain_valid' => $result->verified,
            'ip' => $result->ip,
        ];

        if (! $result->verified || $certificate === null || $days === null) {
            return CheckResult::error('Certificate not valid: '.($result->verifyError ?? 'verification failed').$edge, $data);
        }

        $until = $certificate->expiresAt->setTimezone($timezone)->toDateString();

        if ($days < 0) {
            return CheckResult::error("Expired on {$until}{$edge}", $data);
        }

        $summary = "Valid until {$until} (".trans_choice('{0} expires today|{1} 1 day|[2,*] :count days', $days)."), {$certificate->issuer}{$edge}";

        return match (true) {
            $days <= $arguments->int('error_days') => CheckResult::error($summary, $data),
            $days <= $arguments->int('warning_days') => CheckResult::warning($summary, $data),
            default => CheckResult::ok($summary, $data),
        };
    }
}
