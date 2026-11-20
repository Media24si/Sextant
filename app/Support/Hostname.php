<?php

namespace App\Support;

/**
 * Hostnames and domains are stored lowercase, ASCII (punycode) and without a
 * trailing dot, and shown in Unicode (SPEC §3).
 */
class Hostname
{
    public static function normalize(string $hostname): string
    {
        $hostname = rtrim(mb_strtolower(trim($hostname)), '.');

        if ($hostname === '' || preg_match('/^[\x20-\x7e]*$/', $hostname) === 1) {
            return $hostname;
        }

        $ascii = idn_to_ascii($hostname, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $hostname : $ascii;
    }

    public static function display(string $hostname): string
    {
        if (! str_contains($hostname, 'xn--')) {
            return $hostname;
        }

        $unicode = idn_to_utf8($hostname, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return $unicode === false ? $hostname : $unicode;
    }
}
