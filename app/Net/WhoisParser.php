<?php

namespace App\Net;

use Carbon\CarbonImmutable;

/**
 * Pulls the expiry date and registrar out of a raw WHOIS response, using
 * config('sextant.whois.expiry_patterns') (SPEC §9.3, docs/spikes/tlds.md).
 */
class WhoisParser
{
    public static function expiry(string $raw): ?CarbonImmutable
    {
        foreach ((array) config('sextant.whois.expiry_patterns') as $pattern) {
            if (preg_match((string) $pattern, $raw, $match) === 1) {
                $date = RdapParser::date(trim($match[1]));

                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    public static function registrar(string $raw): ?string
    {
        return preg_match('/^\s*Registrar:\s*(.+?)\s*$/mi', $raw, $match) === 1 ? $match[1] : null;
    }
}
