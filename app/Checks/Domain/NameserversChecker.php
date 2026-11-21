<?php

namespace App\Checks\Domain;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\DomainChecker;
use App\Models\Domain;
use App\Net\DnsResolver;
use App\Net\RdapClient;
use Throwable;

/**
 * `domain.nameservers` (SPEC §9.4): the live NS set against the expected one
 * (the registry's by default), and whether every nameserver answers for the
 * zone with the same SOA serial.
 */
class NameserversChecker extends DomainChecker
{
    public function __construct(
        private DnsResolver $dns,
        private RdapClient $rdap,
    ) {}

    public static function name(): string
    {
        return 'Nameservers';
    }

    public static function arguments(): array
    {
        return [
            Argument::stringList('expected', 'Expected nameservers')
                ->help('Empty = the list the registry has')
                ->rules(['max:13']),
            Argument::bool('check_soa', 'Ask each nameserver for the SOA serial'),
        ];
    }

    public static function trackedFields(): array
    {
        return ['nameservers_live' => 'domain.nameservers_changed'];
    }

    public static function describeChange(string $field, mixed $old, mixed $new): string
    {
        return 'nameservers changed from '.self::plainValue($old).' to '.self::plainValue($new);
    }

    protected function check(Domain $domain, Arguments $arguments): CheckResult
    {
        $live = $this->normalise($this->dns->ns($domain->name));
        $expected = $this->normalise($arguments->stringList('expected'));
        $registry = $expected === [] ? $this->registryList($domain) : $expected;

        $data = ['nameservers_live' => $live, 'nameservers_registry' => $registry, 'soa' => []];

        if ($live === []) {
            return CheckResult::error('No NS records: the domain does not resolve', $data);
        }

        $warnings = [];

        if ($registry !== null && $registry !== $live) {
            $warnings[] = 'Nameservers differ from '.($expected === [] ? 'the registry' : 'the expected list').': live '.implode(', ', $live).'; expected '.implode(', ', $registry);
        }

        if ($arguments->bool('check_soa')) {
            $serials = [];

            foreach ($live as $nameserver) {
                try {
                    $serials[$nameserver] = $this->dns->soa($domain->name, $nameserver)?->serial;
                } catch (CheckCouldNotRun) {
                    $serials[$nameserver] = null;
                }
            }

            $data['soa'] = $serials;
            $answered = array_filter($serials, fn (?int $serial): bool => $serial !== null);

            if ($answered === []) {
                return CheckResult::error('No authoritative nameserver answers for the zone', $data);
            }

            $silent = array_keys(array_diff_key($serials, $answered));

            if ($silent !== []) {
                $warnings[] = implode(', ', $silent).(count($silent) === 1 ? " doesn't" : " don't").' answer for the zone';
            }

            if (count(array_unique($answered)) > 1) {
                $warnings[] = 'SOA serials differ: '.implode(', ', array_map(fn (string $ns, int $serial): string => "{$ns} {$serial}", array_keys($answered), $answered));
            }
        }

        if ($warnings !== []) {
            return CheckResult::warning(implode('; ', $warnings), $data);
        }

        $count = count($live);

        return CheckResult::ok(
            $count.' '.($count === 1 ? 'nameserver' : 'nameservers').($arguments->bool('check_soa') ? ', serials consistent' : ''),
            $data,
        );
    }

    /**
     * The registry's list from RDAP (cached 12 hours). Null when the registry
     * can't say, so the comparison is skipped rather than failing.
     *
     * @return list<string>|null
     */
    private function registryList(Domain $domain): ?array
    {
        try {
            $record = $this->rdap->domain($domain->name);
        } catch (Throwable) {
            return null;
        }

        $list = $record === null ? [] : $this->normalise($record->nameservers);

        return $list === [] ? null : $list;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function normalise(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn (string $name): string => rtrim(strtolower(trim($name)), '.'), $names))));
        sort($names);

        return $names;
    }
}
