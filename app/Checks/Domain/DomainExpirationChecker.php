<?php

namespace App\Checks\Domain;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\DomainChecker;
use App\Models\Domain;
use App\Net\RdapClient;
use App\Net\Results\RdapDomain;
use App\Net\WhoisClient;
use App\Net\WhoisParser;
use Carbon\CarbonImmutable;

/**
 * `domain.expiration`: when does the registration run out (SPEC §9.3)? RDAP
 * first; WHOIS when the TLD has no RDAP service or the RDAP request fails.
 * Writes `domains.expires_at` and `registrar` quietly.
 */
class DomainExpirationChecker extends DomainChecker
{
    /** Registry statuses that mean the domain is about to stop working. */
    private const BAD_STATUSES = ['client hold', 'server hold', 'redemption period', 'pending delete'];

    public function __construct(
        private RdapClient $rdap,
        private WhoisClient $whois,
    ) {}

    public static function name(): string
    {
        return 'Domain expiration';
    }

    public static function arguments(): array
    {
        return [
            Argument::int('warning_days', 'Warn this many days before expiry')->rules(['between:1,365']),
            Argument::int('error_days', 'Error this many days before expiry')->rules(['between:0,365']),
        ];
    }

    public static function trackedFields(): array
    {
        return [
            'expires_at' => 'domain.renewed',
            'registrar' => 'domain.registrar_changed',
        ];
    }

    public static function describeChange(string $field, mixed $old, mixed $new): string
    {
        return $field === 'expires_at'
            ? 'renewed until '.self::plainValue($new).' (was '.self::plainValue($old).')'
            : 'registrar changed from '.self::plainValue($old).' to '.self::plainValue($new);
    }

    protected function check(Domain $domain, Arguments $arguments): CheckResult
    {
        [$record, $source] = $this->lookUp($domain->name);

        $data = [
            'expires_at' => $record->expiresAt?->toDateString(),
            'registered_at' => $record->registeredAt?->toDateString(),
            'last_changed_at' => $record->lastChangedAt?->toDateString(),
            'registrar' => $record->registrar,
            'statuses' => $record->statuses,
            'nameservers' => $record->nameservers,
            'source' => $source,
        ];

        if (! $record->found) {
            return CheckResult::error('Domain not found in registry', $data);
        }

        if ($record->expiresAt === null) {
            throw new CheckCouldNotRun('Could not determine expiry date');
        }

        $domain->forceFill(['expires_at' => $record->expiresAt->toDateString(), 'registrar' => $record->registrar ?? $domain->registrar])->saveQuietly();

        $bad = array_values(array_intersect($record->statuses, self::BAD_STATUSES));

        if ($bad !== []) {
            return CheckResult::error('Registry status: '.implode(', ', $bad), $data);
        }

        $timezone = (string) config('sextant.display_timezone');
        $days = (int) CarbonImmutable::now($timezone)->startOfDay()->diffInDays($record->expiresAt->startOfDay(), false);
        $date = $record->expiresAt->toDateString();

        if ($days < 0) {
            return CheckResult::error("Expired on {$date}", $data);
        }

        $summary = "Expires {$date} (".trans_choice('{0} today|{1} 1 day|[2,*] :count days', $days).')';

        return match (true) {
            $days <= $arguments->int('error_days') => CheckResult::error($summary, $data),
            $days <= $arguments->int('warning_days') => CheckResult::warning($summary, $data),
            default => CheckResult::ok($summary, $data),
        };
    }

    /**
     * @return array{RdapDomain, string}
     */
    private function lookUp(string $name): array
    {
        $rdapFailure = null;

        try {
            $record = $this->rdap->domain($name, fresh: true);

            if ($record !== null) {
                return [$record, 'rdap'];
            }
        } catch (CheckCouldNotRun $exception) {
            // A rate limit means slowing down, not asking another server instead.
            if (str_contains($exception->getMessage(), 'rate limit')) {
                throw $exception;
            }

            $rdapFailure = $exception;
        }

        $raw = $this->whois->query($name);
        $expires = WhoisParser::expiry($raw);

        if ($expires === null) {
            throw $rdapFailure ?? new CheckCouldNotRun('Could not determine expiry date');
        }

        return [new RdapDomain(found: true, server: 'whois', expiresAt: $expires, registrar: WhoisParser::registrar($raw)), 'whois'];
    }
}
