<?php

use App\Events\DomainAdded;
use App\Events\SiteAdded;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Models\User;
use App\Net\DnsResolver;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->domain = Domain::factory()->create(['name' => 'svet24.si']);
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    ServerAddress::factory()->for($this->web02)->primary()->create(['ip' => '192.0.2.10']);
    $this->varnish = ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80']]);
});

function addSitePayload(array $overrides = []): array
{
    return [
        'hostname' => 'podjetje.svet24.si',
        'scheme' => 'https',
        'port' => null,
        'path' => '/',
        'front_service_id' => test()->varnish->id,
        'server_id' => test()->web02->id,
        'description' => 'Business section',
        'github_url' => 'https://github.com/media24/podjetje/',
        'expected_target' => null,
        'add_www' => false,
        ...array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $overrides),
    ];
}

test('the add site page offers the services and servers to choose from', function () {
    $this->actingAs($this->admin)
        ->get(route('sites.create', ['url' => 'podjetje.svet24.si']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Add')
            ->where('url', 'podjetje.svet24.si')
            ->has('front_services', 2)
            ->has('servers', 1));
});

test('"Add site on a domain" starts the URL with the domain', function () {
    $this->actingAs($this->admin)
        ->get(route('sites.create', ['domain' => $this->domain->id]))
        ->assertInertia(fn (Assert $page) => $page->where('domain', 'svet24.si')->where('url', null));
});

test('detection answers with JSON and saves nothing', function () {
    app(DnsResolver::class)->answer('podjetje.svet24.si', ['192.0.2.10']);

    $this->actingAs($this->admin)
        ->postJson(route('sites.detect'), ['url' => 'https://podjetje.svet24.si/novice'])
        ->assertOk()
        ->assertJsonPath('hostname', 'podjetje.svet24.si')
        ->assertJsonPath('path', '/novice')
        ->assertJsonPath('domain.value.existing_id', $this->domain->id)
        ->assertJsonPath('backend.value.name', 'web02')
        ->assertJsonPath('backend.confidence', 'detected');

    expect(Site::query()->count())->toBe(0);
});

test('an invalid URL is a validation error', function (string $url, string $message) {
    $this->actingAs($this->admin)
        ->postJson(route('sites.detect'), ['url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => $message]);
})->with([
    'IP address' => ['192.0.2.10', 'IP address'],
    'empty' => ['', 'required'],
]);

test('an admin adds a reviewed site', function () {
    Event::fake([SiteAdded::class, DomainAdded::class]);

    $this->actingAs($this->admin)
        ->post(route('sites.store'), addSitePayload(['hostname' => 'Podjetje.Svet24.si.', 'path' => '/novice']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('sites.show', Site::query()->sole()));

    $site = Site::query()->sole();

    expect($site->hostname)->toBe('podjetje.svet24.si')
        ->and($site->domain_id)->toBe($this->domain->id)
        ->and($site->servingPath()->toArray())->toBe(['front' => 'Varnish', 'backend' => 'web02'])
        ->and($site->github_url)->toBe('https://github.com/media24/podjetje')
        ->and(Activity::query()->where('type', 'site.created')->exists())->toBeTrue();

    Event::assertDispatched(SiteAdded::class, fn (SiteAdded $event) => $event->site->is($site)
        && collect($event->monitors)->firstWhere('checker', 'site.http')->arguments === ['path' => '/novice']);
    Event::assertNotDispatched(DomainAdded::class);
});

test('a site on a new domain creates the domain too', function () {
    Event::fake([SiteAdded::class, DomainAdded::class]);

    $this->actingAs($this->admin)
        ->post(route('sites.store'), addSitePayload(['hostname' => 'shop.oglasi24.si', 'front_service_id' => null]))
        ->assertSessionHasNoErrors();

    $domain = Domain::query()->where('name', 'oglasi24.si')->sole();

    expect(Site::query()->sole()->domain_id)->toBe($domain->id);
    Event::assertDispatched(DomainAdded::class, fn (DomainAdded $event) => $event->domain->is($domain)
        && collect($event->monitors)->pluck('checker')->all() === ['domain.expiration', 'domain.nameservers']);
});

test('the www site can be added in the same save', function () {
    Event::fake([SiteAdded::class]);

    $this->actingAs($this->admin)
        ->post(route('sites.store'), addSitePayload([
            'hostname' => 'svet24.si',
            'expected_target' => 'https://www.svet24.si/',
            'add_www' => true,
        ]))
        ->assertSessionHasNoErrors();

    $www = Site::query()->where('hostname', 'www.svet24.si')->sole();

    expect($www->servingPath()->toArray())->toBe(['front' => 'Varnish', 'backend' => 'web02'])
        ->and($www->description)->toBeNull();
    Event::assertDispatched(SiteAdded::class, fn (SiteAdded $event) => $event->site->hostname === 'svet24.si'
        && collect($event->monitors)->firstWhere('checker', 'site.redirect')->arguments === ['expected_target' => 'https://www.svet24.si/']);
    Event::assertDispatchedTimes(SiteAdded::class, 2);
});

test('nothing is saved when one of the sites is invalid', function () {
    Site::factory()->for($this->domain)->create(['hostname' => 'www.svet24.si']);

    $this->actingAs($this->admin)
        ->post(route('sites.store'), addSitePayload(['hostname' => 'svet24.si', 'add_www' => true]))
        ->assertSessionHasErrors('add_www');

    expect(Site::query()->where('hostname', 'svet24.si')->exists())->toBeFalse();
});

test('added sites are validated', function (array $overrides, string $error) {
    Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si']);

    $this->actingAs($this->admin)
        ->post(route('sites.store'), addSitePayload($overrides))
        ->assertSessionHasErrors($error);
})->with([
    'hostname taken' => [['hostname' => 'svet24.si'], 'hostname'],
    'public suffix' => [['hostname' => 'co.uk'], 'hostname'],
    'not a hostname' => [['hostname' => 'https://podjetje.svet24.si'], 'hostname'],
    'path without slash' => [['path' => 'novice'], 'path'],
    'bad redirect target' => [['expected_target' => 'javascript:alert(1)'], 'expected_target'],
    'deleted backend' => [['server_id' => fn () => tap(Server::factory()->create())->delete()->id], 'server_id'],
]);

test('Add server from an unknown address comes back to Add site', function () {
    $returnTo = route('sites.create', ['url' => 'novo.svet24.si'], false);

    $this->actingAs($this->admin)
        ->get(route('servers.create', ['ip' => '198.51.100.7', 'return_to' => $returnTo]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('form.addresses.0.ip', '198.51.100.7')
            ->where('return_to', $returnTo));

    $this->post(route('servers.store'), [
        'name' => 'web09',
        'addresses' => [['ip' => '198.51.100.7', 'is_primary' => true]],
        'return_to' => $returnTo,
    ])->assertRedirect($returnTo);
});

test('the external service form is prefilled the same way', function () {
    $this->actingAs($this->admin)
        ->get(route('external-services.create', ['ip' => '198.51.100.7', 'return_to' => '/sites/create']))
        ->assertInertia(fn (Assert $page) => $page->where('form.ranges', ['198.51.100.7'])->where('return_to', '/sites/create'));
});

test('return_to only goes to paths on this app', function (string $returnTo) {
    $this->actingAs($this->admin)
        ->get(route('servers.create', ['return_to' => $returnTo]))
        ->assertInertia(fn (Assert $page) => $page->where('return_to', null));
})->with(['https://evil.example/', '//evil.example/', '/\\evil.example']);

test('detection runs again on a site without calling it a duplicate', function () {
    $site = Site::factory()->for($this->domain)->create(['hostname' => 'podjetje.svet24.si', 'server_id' => $this->web02->id]);
    app(DnsResolver::class)->answer('podjetje.svet24.si', ['203.0.113.80']);

    $this->actingAs($this->admin)
        ->postJson(route('sites.detect-again', $site))
        ->assertOk()
        ->assertJsonPath('duplicate', null)
        ->assertJsonPath('front.value.name', 'Varnish')
        ->assertJsonPath('front.confidence', 'detected');

    expect($site->fresh()->front_service_id)->toBeNull();
});

test('the confirmed serving path is applied and reaches the change feed', function () {
    $site = Site::factory()->for($this->domain)->create(['hostname' => 'podjetje.svet24.si', 'server_id' => $this->web02->id]);

    $this->actingAs($this->admin)
        ->put(route('sites.path', $site), ['front_service_id' => $this->varnish->id, 'server_id' => $this->web02->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('sites.show', $site));

    expect($site->fresh()->servingPath()->toArray())->toBe(['front' => 'Varnish', 'backend' => 'web02'])
        ->and(Activity::query()->where('type', 'site.path_changed')->sole()->summary)
        ->toBe('podjetje.svet24.si serving path: Varnish → web02 (was direct → web02)');
});

test('the serving path only takes live services and servers', function () {
    $site = Site::factory()->for($this->domain)->create();

    $this->actingAs($this->admin)
        ->put(route('sites.path', $site), ['front_service_id' => 999, 'server_id' => tap(Server::factory()->create())->delete()->id])
        ->assertSessionHasErrors(['front_service_id', 'server_id']);
});
