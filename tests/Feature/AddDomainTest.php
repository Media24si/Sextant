<?php

use App\Events\DomainAdded;
use App\Events\SiteAdded;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Net\RdapClient;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    $this->varnish = ExternalService::factory()->create(['name' => 'Varnish']);
});

function domainSite(array $overrides = []): array
{
    return [
        'hostname' => 'svet24.si',
        'scheme' => 'https',
        'port' => null,
        'path' => '/',
        'front_service_id' => test()->varnish->id,
        'server_id' => test()->web02->id,
        'expected_target' => null,
        ...$overrides,
    ];
}

test('the add domain page offers services and servers', function () {
    $this->actingAs($this->admin)
        ->get(route('domains.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/Add')
            ->has('front_services', 2)
            ->has('servers', 1));
});

test('the lookup previews the registry for a new domain', function () {
    app(RdapClient::class)->fixture('svet24.si', 'svet24.si.json', 'https://rdap.register.si/');

    $this->actingAs($this->admin)
        ->postJson(route('domains.lookup'), ['name' => 'SVET24.si.'])
        ->assertOk()
        ->assertJsonPath('name', 'svet24.si')
        ->assertJsonPath('existing_id', null)
        ->assertJsonPath('registry.server', 'rdap.register.si')
        ->assertJsonPath('registry.expires_at', '2027-07-18')
        ->assertJsonPath('registry.nameservers', ['dns1.media24.si', 'dns2.media24.si', 'dns3.media24.si']);
});

test('the lookup points at a domain that is already in Sextant', function () {
    $domain = Domain::factory()->create(['name' => 'svet24.si']);

    $this->actingAs($this->admin)
        ->postJson(route('domains.lookup'), ['name' => 'svet24.si'])
        ->assertOk()
        ->assertJsonPath('existing_id', $domain->id)
        ->assertJsonPath('registry', null);
});

test('the lookup wants the registrable domain', function (string $name, string $message) {
    $this->actingAs($this->admin)
        ->postJson(route('domains.lookup'), ['name' => $name])
        ->assertJsonValidationErrors(['name' => $message]);
})->with([
    'subdomain' => ['podjetje.svet24.si', 'Enter the domain you renew, svet24.si, not a subdomain.'],
    'public suffix' => ['co.uk', 'co.uk is a public suffix, not a domain.'],
    'not a domain' => ['svet24', 'Enter a domain such as svet24.si.'],
]);

test('a domain is saved with its subdomains in one go', function () {
    Event::fake([SiteAdded::class, DomainAdded::class]);

    $this->actingAs($this->admin)
        ->post(route('domains.store'), [
            'name' => 'svet24.si',
            'notes' => 'Main news site',
            'sites' => [
                domainSite(['expected_target' => 'https://www.svet24.si/']),
                domainSite(['hostname' => 'Podjetje.svet24.si', 'server_id' => null, 'path' => '/novice']),
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Domain svet24.si added with 2 sites.');

    $domain = Domain::query()->sole();

    expect($domain->notes)->toBe('Main news site')
        ->and($domain->sites()->orderBy('hostname')->pluck('hostname')->all())->toBe(['podjetje.svet24.si', 'svet24.si'])
        ->and(Site::query()->where('hostname', 'podjetje.svet24.si')->value('server_id'))->toBeNull();
    Event::assertDispatchedTimes(DomainAdded::class, 1);
    Event::assertDispatched(SiteAdded::class, fn (SiteAdded $event) => $event->site->hostname === 'podjetje.svet24.si'
        && collect($event->monitors)->firstWhere('checker', 'site.http')->arguments === ['path' => '/novice']);
});

test('nothing is saved when a subdomain is invalid', function (array $site, string $error) {
    Site::factory()->for(Domain::factory()->create(['name' => 'media24.si']))->create(['hostname' => 'taken.media24.si']);

    $this->actingAs($this->admin)
        ->post(route('domains.store'), ['name' => 'svet24.si', 'sites' => [domainSite(), domainSite($site)]])
        ->assertSessionHasErrors($error);

    expect(Domain::query()->where('name', 'svet24.si')->exists())->toBeFalse();
})->with([
    'another domain' => [['hostname' => 'taken.media24.si'], 'sites.1.hostname'],
    'listed twice' => [['hostname' => 'svet24.si'], 'sites.1.hostname'],
    'deleted server' => [['hostname' => 'a.svet24.si', 'server_id' => 999], 'sites.1.server_id'],
]);
