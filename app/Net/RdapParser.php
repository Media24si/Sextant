<?php

namespace App\Net;

use App\Net\Results\RdapDomain;
use Carbon\CarbonImmutable;

/**
 * Reads an RDAP domain response (docs/spikes/rdap.md, docs/spikes/tlds.md).
 * Dates come as `Y-m-d` (.si), RFC 3339 (.com) or RFC 3339 with milliseconds
 * (.dev); they become calendar dates in Europe/Ljubljana (SPEC §9.3).
 */
class RdapParser
{
    /**
     * @param  array<string, mixed>  $json
     */
    public static function parse(array $json, string $server): RdapDomain
    {
        $events = [];

        foreach ((array) ($json['events'] ?? []) as $event) {
            if (is_array($event) && is_string($event['eventAction'] ?? null) && is_string($event['eventDate'] ?? null)) {
                $events[strtolower($event['eventAction'])] = self::date($event['eventDate']);
            }
        }

        return new RdapDomain(
            found: true,
            server: $server,
            expiresAt: $events['expiration'] ?? null,
            registeredAt: $events['registration'] ?? null,
            lastChangedAt: $events['last changed'] ?? null,
            registrar: self::registrar((array) ($json['entities'] ?? [])),
            statuses: array_values(array_map(
                fn (mixed $status): string => strtolower((string) $status),
                (array) ($json['status'] ?? []),
            )),
            nameservers: array_values(array_filter(array_map(
                fn (mixed $nameserver): string => is_array($nameserver) ? rtrim(strtolower((string) ($nameserver['ldhName'] ?? '')), '.') : '',
                (array) ($json['nameservers'] ?? []),
            ))),
        );
    }

    public static function date(string $value): ?CarbonImmutable
    {
        $timezone = (string) config('sextant.display_timezone');

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: null;
            }

            return CarbonImmutable::parse($value)->setTimezone($timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The entity with the `registrar` role, its `fn` from the vCard.
     *
     * @param  array<mixed>  $entities
     */
    private static function registrar(array $entities): ?string
    {
        foreach ($entities as $entity) {
            if (! is_array($entity) || ! in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                continue;
            }

            foreach ((array) ($entity['vcardArray'][1] ?? []) as $property) {
                if (is_array($property) && ($property[0] ?? null) === 'fn' && is_string($property[3] ?? null) && $property[3] !== '') {
                    return $property[3];
                }
            }
        }

        return null;
    }
}
