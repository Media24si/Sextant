<?php

use App\Detection\InvalidUrl;
use App\Detection\PublicSuffixList;
use App\Detection\UrlNormalizer;

test('URLs are cleaned up', function (string $input, string $hostname, string $scheme, ?int $port, string $path) {
    $url = (new UrlNormalizer)->normalize($input);

    expect([$url->hostname, $url->scheme, $url->port, $url->path])->toBe([$hostname, $scheme, $port, $path]);
})->with([
    'bare hostname' => ['vestnik.svet24.si', 'vestnik.svet24.si', 'https', null, '/'],
    'upper case' => ['HTTPS://Vestnik.SVET24.si/', 'vestnik.svet24.si', 'https', null, '/'],
    'trailing dot' => ['https://svet24.si./', 'svet24.si', 'https', null, '/'],
    'spaces' => ['  svet24.si  ', 'svet24.si', 'https', null, '/'],
    'http' => ['http://svet24.si', 'svet24.si', 'http', null, '/'],
    'IDN' => ['https://šola.si', 'xn--ola-zza.si', 'https', null, '/'],
    'IDN upper case' => ['ŠOLA.SI', 'xn--ola-zza.si', 'https', null, '/'],
    'default port dropped' => ['https://svet24.si:443', 'svet24.si', 'https', null, '/'],
    'other port kept' => ['https://svet24.si:8443/', 'svet24.si', 'https', 8443, '/'],
    'path kept' => ['https://svet24.si/zdravje/', 'svet24.si', 'https', null, '/zdravje/'],
    'query kept' => ['svet24.si/iskanje?q=1', 'svet24.si', 'https', null, '/iskanje?q=1'],
]);

test('things that can\'t be sites are refused', function (string $input, string $message) {
    expect(fn () => (new UrlNormalizer)->normalize($input))->toThrow(InvalidUrl::class, $message);
})->with([
    'empty' => ['  ', 'Enter a URL'],
    'IPv4' => ['https://192.0.2.12/', 'not an IP address'],
    'IPv6' => ['http://[2001:db8::1]/', 'not an IP address'],
    'no dot' => ['https://intranet/', 'isn\'t a full hostname'],
    'ftp' => ['ftp://svet24.si', 'Only http and https'],
    'underscore' => ['https://bad_host.svet24.si', 'isn\'t a valid hostname'],
]);

test('the registrable domain comes from the Public Suffix List', function (string $hostname, ?string $domain) {
    expect((new PublicSuffixList)->registrableDomain($hostname))->toBe($domain);
})->with([
    ['vestnik.svet24.si', 'svet24.si'],
    ['svet24.si', 'svet24.si'],
    ['www.example.co.uk', 'example.co.uk'],
    ['a.b.dobertek.com', 'dobertek.com'],
    ['xn--ola-zza.si', 'xn--ola-zza.si'],
    ['si', null],
    ['co.uk', null],
]);
