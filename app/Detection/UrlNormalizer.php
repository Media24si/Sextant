<?php

namespace App\Detection;

use App\Support\Hostname;

/**
 * SPEC §10.1 step 1: trim; add https:// when there's no scheme; lowercase the
 * host; strip a trailing dot; IDN → punycode (UTS #46); reject IP literals and
 * hosts without a dot; keep a non-root path for the HTTP monitor.
 */
class UrlNormalizer
{
    /**
     * @throws InvalidUrl
     */
    public function normalize(string $input): NormalizedUrl
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidUrl('Enter a URL such as https://vestnik.svet24.si.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) !== 1) {
            $input = 'https://'.ltrim($input, '/');
        }

        $parts = parse_url($input);

        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidUrl("\"{$input}\" isn't a URL.");
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidUrl('Only http and https sites can be added.');
        }

        $host = trim($parts['host'], '[]');

        if (filter_var(rtrim($host, '.'), FILTER_VALIDATE_IP) !== false) {
            throw new InvalidUrl('Enter the site\'s hostname, not an IP address.');
        }

        $hostname = Hostname::normalize($host);

        if (! str_contains($hostname, '.')) {
            throw new InvalidUrl("\"{$host}\" isn't a full hostname; it needs a domain such as svet24.si.");
        }

        if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+(xn--[a-z0-9-]{1,59}|[a-z]{2,63})$/', $hostname) !== 1 || strlen($hostname) > 253) {
            throw new InvalidUrl("\"{$host}\" isn't a valid hostname.");
        }

        $port = $parts['port'] ?? null;

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];

        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        return new NormalizedUrl($scheme, $hostname, $port, $path);
    }
}
