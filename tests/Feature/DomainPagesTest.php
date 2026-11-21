<?php

use App\Enums\Status;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reader = User::factory()->reader()->create();
});

afterEach(fn () => Carbon::setTestNow());

test('readers see domains with their subdomains, apex first', function () {
    $web02 = Server::factory()->create(['name' => 'web02']);
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    $domain = Domain::factory()->create(['name' => 'svet24.si']);
    Site::factory()->for($domain)->create(['hostname' => 'podjetje.svet24.si', 'server_id' => $web02->id, 'front_service_id' => $varnish->id, 'github_url' => 'https://github.com/media24/podjetje']);
    Site::factory()->for($domain)->create(['hostname' => 'svet24.si', 'server_id' => $web02->id, 'description' => 'News portal']);
    Site::factory()->for($domain)->create(['hostname' => 'ekipa.svet24.si'])->forceFill(['status' => Status::Error])->saveQuietly();

    $this->actingAs($this->reader)
        ->get(route('domains.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/Index')
            ->where('rows.data.0.name', 'svet24.si')
            ->where('rows.data.0.failing_count', 1)
            ->where('rows.data.0.sites.0.hostname', 'svet24.si')
            ->where('rows.data.0.sites.0.description', 'News portal')
            ->where('rows.data.0.sites.0.serving_path', ['front' => null, 'backend' => 'web02'])
            ->where('rows.data.0.sites.1.hostname', 'ekipa.svet24.si')
            ->where('rows.data.0.sites.2.serving_path', ['front' => 'Varnish', 'backend' => 'web02'])
            ->where('rows.data.0.sites.2.github_url', 'https://github.com/media24/podjetje')
            ->where('counts', ['all' => 1, 'sites' => 3, 'deleted' => 0])
            ->where('nav.domains', 1));
});

test('the filter matches domains, subdomains, backends and front services', function (string $term, array $expected) {
    $web03 = Server::factory()->create(['name' => 'web03']);
    $cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();
    $svet = Domain::factory()->create(['name' => 'svet24.si']);
    Site::factory()->for($svet)->create(['hostname' => 'ekipa.svet24.si', 'server_id' => $web03->id]);
    $necenzurirano = Domain::factory()->create(['name' => 'necenzurirano.si']);
    Site::factory()->for($necenzurirano)->create(['hostname' => 'necenzurirano.si', 'front_service_id' => $cloudflare->id, 'description' => 'Investigative news']);
    Domain::factory()->create(['name' => 'media24.si']);

    $names = collect($this->actingAs($this->reader)->get(route('domains.index', ['filter' => ['search' => $term]]))
        ->viewData('page')['props']['rows']['data'])->pluck('name')->sort()->values()->all();

    expect($names)->toBe($expected);
})->with([
    'domain name' => ['media24', ['media24.si']],
    'subdomain' => ['ekipa', ['svet24.si']],
    'backend server' => ['web03', ['svet24.si']],
    'front service' => ['cloudflare', ['necenzurirano.si']],
    'description' => ['investigative', ['necenzurirano.si']],
]);

test('expiry is shown in calendar days in Ljubljana', function () {
    Carbon::setTestNow('2026-11-20 23:30:00');  // already 21 Nov in Ljubljana
    $domain = Domain::factory()->create(['name' => 'svet24.si']);
    $domain->forceFill(['expires_at' => '2027-07-18', 'registrar' => 'INFONET MEDIA d.o.o.'])->saveQuietly();

    $this->actingAs($this->reader)
        ->get(route('domains.show', $domain))
        ->assertInertia(fn (Assert $page) => $page
            ->component('domains/Show')
            ->where('domain.expires_at', '2027-07-18')
            ->where('domain.expires_in_days', 239)
            ->where('domain.registrar', 'INFONET MEDIA d.o.o.'));
});

test('the domain page lists its sites and the changes to it and them', function () {
    $this->actingAs($this->admin);
    $domain = Domain::factory()->create(['name' => 'svet24.si']);
    Site::factory()->for($domain)->create(['hostname' => 'svet24.si']);

    $this->get(route('domains.show', $domain))
        ->assertInertia(fn (Assert $page) => $page
            ->has('domain.sites', 1)
            ->where('activity.0.type', 'site.created')
            ->where('activity.1.type', 'domain.created'));
});

test('the domain page shows its nameservers: live DNS first, else the registry\'s', function () {
    $this->actingAs($this->reader);
    $domain = Domain::factory()->create(['name' => 'svet24.si']);
    $expiration = Monitor::factory()->on($domain, 'domain.expiration')->create();
    $nameservers = Monitor::factory()->on($domain, 'domain.nameservers')->create();

    $this->get(route('domains.show', $domain))
        ->assertInertia(fn (Assert $page) => $page->where('nameservers', ['nameservers' => [], 'source' => null]));

    $expiration->state->update(['data' => ['nameservers' => ['NS2.Example.si.', 'ns1.example.si']], 'checked_at' => now()]);

    $this->get(route('domains.show', $domain))
        ->assertInertia(fn (Assert $page) => $page->where('nameservers', ['nameservers' => ['ns1.example.si', 'ns2.example.si'], 'source' => 'registry']));

    $nameservers->state->update(['data' => ['nameservers_live' => ['bob.ns.cloudflare.com', 'ada.ns.cloudflare.com']], 'checked_at' => now()]);

    $this->get(route('domains.show', $domain))
        ->assertInertia(fn (Assert $page) => $page->where('nameservers', ['nameservers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'source' => 'dns']));
});

test('an admin adds and edits a domain; the name is stored as punycode', function () {
    $this->actingAs($this->admin)
        ->post(route('domains.store'), ['name' => 'ŠOLA.si.', 'notes' => 'School', 'sites' => []])
        ->assertSessionHasNoErrors();

    $domain = Domain::query()->sole();
    expect($domain->name)->toBe('xn--ola-zza.si');

    $this->get(route('domains.edit', $domain))
        ->assertInertia(fn (Assert $page) => $page->where('form.name', 'šola.si'));

    $this->put(route('domains.update', $domain), ['name' => 'šola.si', 'notes' => 'Schools'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('domains.show', $domain));
});

test('domain names are validated', function (string $name) {
    Domain::factory()->create(['name' => 'svet24.si']);

    $this->actingAs($this->admin)
        ->post(route('domains.store'), ['name' => $name, 'sites' => []])
        ->assertSessionHasErrors('name');
})->with(['SVET24.si', 'svet24', 'not a domain', 'https://svet24.si', 'news.svet24.si', 'co.uk']);

test('a domain with sites is not deleted; an empty one is, and comes back', function () {
    $domain = Domain::factory()->create(['name' => 'svet24.si']);
    $site = Site::factory()->for($domain)->create();

    $this->actingAs($this->admin)
        ->from(route('domains.show', $domain))
        ->delete(route('domains.destroy', $domain))
        ->assertInertiaFlash('toast.message', 'svet24.si still has 1 site. Delete them first.');
    expect($domain->fresh()->trashed())->toBeFalse();

    $site->delete();
    $this->delete(route('domains.destroy', $domain))->assertRedirect(route('domains.index'));
    expect($domain->fresh()->trashed())->toBeTrue();

    $this->post(route('domains.restore', $domain))->assertRedirect(route('domains.show', $domain));
    expect($domain->fresh()->trashed())->toBeFalse();
});
