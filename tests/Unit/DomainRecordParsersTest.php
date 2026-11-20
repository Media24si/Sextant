<?php

use App\Net\RdapParser;
use App\Net\WhoisParser;

function rdapFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/rdap/{$name}.json")), true);
}

function whoisFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/whois/{$name}.txt"));
}

it('reads the expiry date in each form registries send', function (string $fixture, string $expires) {
    expect(RdapParser::parse(rdapFixture($fixture), 'https://rdap.example/')->expiresAt?->toDateString())->toBe($expires);
})->with([
    '.si, date only' => ['svet24.si', '2027-07-18'],
    '.com, RFC 3339' => ['example.com', '2027-08-13'],
    '.dev, with milliseconds' => ['web.dev', '2027-10-29'],
]);

it('reads registration, change date, status and lowercase nameservers', function () {
    $domain = RdapParser::parse(rdapFixture('example.com'), 'https://rdap.verisign.com/com/v1/');

    expect($domain->found)->toBeTrue()
        ->and($domain->server)->toBe('https://rdap.verisign.com/com/v1/')
        ->and($domain->registeredAt?->toDateString())->toBe('1995-08-14')
        ->and($domain->lastChangedAt?->toDateString())->toBe('2026-08-14')
        ->and($domain->nameservers)->toBe(['elliott.ns.cloudflare.com', 'hera.ns.cloudflare.com'])
        ->and($domain->statuses)->toContain('client transfer prohibited');
});

it('gives dates as Ljubljana calendar days', function () {
    expect(RdapParser::date('2050-11-15T23:00:00Z')?->toDateString())->toBe('2050-11-16')
        ->and(RdapParser::date('not a date'))->toBeNull();
});

it('finds the expiry date in WHOIS text', function (string $fixture, ?string $expires) {
    expect(WhoisParser::expiry(whoisFixture($fixture))?->toDateString())->toBe($expires);
})->with([
    '.me' => ['nic.me', '2027-04-29'],
    '.hr' => ['carnet.hr', '2050-11-16'],
    '.eu has none' => ['europa.eu', null],
]);

it('finds the registrar in WHOIS text', function () {
    expect(WhoisParser::registrar(whoisFixture('carnet.hr')))->toBe('CARNET');
});
