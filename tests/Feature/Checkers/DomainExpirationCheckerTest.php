<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\Monitor;
use App\Net\RdapClient;
use App\Net\Results\RdapDomain;
use App\Net\WhoisClient;
use Carbon\CarbonImmutable;

/* `domain.expiration` (SPEC §9.3). */
function expirationCheck(Domain $domain): CheckResult
{
    $registry = app(CheckerRegistry::class);

    return $registry->checker('domain.expiration')->run($domain, $registry->settings(Monitor::factory()->on($domain, 'domain.expiration')->make())->arguments);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'Europe/Ljubljana'));
    $this->domain = Domain::factory()->create(['name' => 'svet24.si']);
});

it('reads the expiry from RDAP and stores it quietly', function () {
    app(RdapClient::class)->fixture('svet24.si', 'svet24.si.json', 'https://rdap.register.si/');

    $result = expirationCheck($this->domain);

    expect($result)->status->toBe(Status::Ok)->message->toBe('Expires 2027-07-18 (239 days)')
        ->and($result->data)->toMatchArray(['expires_at' => '2027-07-18', 'source' => 'rdap', 'nameservers' => ['dns1.media24.si', 'dns2.media24.si', 'dns3.media24.si']])
        ->and($this->domain->fresh()->expires_at->toDateString())->toBe('2027-07-18')
        ->and(Activity::query()->where('type', 'domain.updated')->exists())->toBeFalse();
});

it('stores nothing when run with --no-save', function () {
    app(RdapClient::class)->fixture('svet24.si', 'svet24.si.json', 'https://rdap.register.si/');
    $monitor = Monitor::factory()->on($this->domain, 'domain.expiration')->create();

    $this->artisan('monitors:run', ['monitor' => $monitor->id, '--no-save' => true])
        ->expectsOutputToContain('Expires 2027-07-18')
        ->assertSuccessful();

    expect($this->domain->fresh()->expires_at)->toBeNull();
});

it('grades the days left', function (string $expires, Status $status) {
    app(RdapClient::class)->record('svet24.si', new RdapDomain(true, 'rdap.example', expiresAt: CarbonImmutable::parse($expires, 'Europe/Ljubljana')));

    expect(expirationCheck($this->domain)->status)->toBe($status);
})->with([
    'in 20 days' => ['2026-12-11', Status::Warning],
    'in 5 days' => ['2026-11-26', Status::Error],
    'expired' => ['2026-11-01', Status::Error],
]);

it('is an error when the registry does not know the domain', function () {
    app(RdapClient::class)->notFound('svet24.si');

    expect(expirationCheck($this->domain))->status->toBe(Status::Error)->message->toBe('Domain not found in registry');
});

it('is an error for a status that is about to stop the domain', function () {
    app(RdapClient::class)->record('svet24.si', new RdapDomain(true, 'rdap.example', expiresAt: CarbonImmutable::parse('2027-07-18'), statuses: ['pending delete']));

    expect(expirationCheck($this->domain)->message)->toBe('Registry status: pending delete');
});

it('falls back to WHOIS for a TLD without RDAP', function () {
    $domain = Domain::factory()->create(['name' => 'primer.me']);
    app(WhoisClient::class)->respond('primer.me', (string) file_get_contents(base_path('tests/Fixtures/whois/nic.me.txt')));

    expect(expirationCheck($domain)->data)->toMatchArray(['expires_at' => '2027-04-29', 'source' => 'whois']);
});

it('does not run on a rate limit, and does not ask WHOIS instead', function () {
    app(RdapClient::class)->fail('svet24.si', 'RDAP rate limit reached');
    app(WhoisClient::class)->respond('svet24.si', 'Registry Expiry Date: 2027-07-18');

    expirationCheck($this->domain);
})->throws(CheckCouldNotRun::class, 'RDAP rate limit reached');

it('uses WHOIS when an RDAP request fails', function () {
    app(RdapClient::class)->fail('svet24.si', 'RDAP request failed (HTTP 503)');
    app(WhoisClient::class)->respond('svet24.si', "expire: 2027-07-18\n");

    expect(expirationCheck($this->domain)->data['source'])->toBe('whois');
});

it('cannot run when neither source gives a date', function () {
    app(WhoisClient::class)->respond('svet24.si', 'No match');

    expirationCheck($this->domain);
})->throws(CheckCouldNotRun::class, 'Could not determine expiry date');
