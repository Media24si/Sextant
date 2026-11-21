<?php

use App\Models\Application;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Models\User;
use App\Net\DnsResolver;
use App\Net\HttpProbe;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Bulk import (SPEC §10.6): detected sites are created, uncertain backends
 * go to Needs review, duplicates and non-URLs are skipped.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    ServerAddress::factory()->for($this->web02)->primary()->create(['ip' => '192.0.2.10']);
    $this->varnish = ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80']]);
    $this->svet24 = Domain::factory()->create(['name' => 'svet24.si']);
    Site::factory()->for($this->svet24)->create(['hostname' => 'svet24.si', 'front_service_id' => $this->varnish->id, 'server_id' => $this->web02->id]);

    $dns = app(DnsResolver::class);
    $dns->answer('direct.svet24.si', ['192.0.2.10']);
    $dns->answer('vestnik.svet24.si', ['203.0.113.80']);
    $dns->answer('shop.oglasi24.si', ['203.0.113.80']);
    $dns->answer('oglasi24.si', ['192.0.2.10']);
    app(HttpProbe::class)->respond('https://oglasi24.si/', 200, redirectsTo: 'https://www.oglasi24.si/');
});

function importUrl(string $url, array $options = []): array
{
    return test()->actingAs(test()->admin)
        ->postJson(route('sites.import.store'), ['url' => $url, ...$options])
        ->assertOk()
        ->json();
}

test('a fully detected site is created with its serving path', function () {
    $result = importUrl('direct.svet24.si/novice');

    $site = Site::query()->where('hostname', 'direct.svet24.si')->sole();

    expect($result)->toMatchArray(['outcome' => 'created', 'message' => 'web02', 'site_id' => $site->id])
        ->and($site->server_id)->toBe($this->web02->id)
        ->and($site->front_service_id)->toBeNull();
});

test('a suggested backend is left for review', function () {
    $result = importUrl('vestnik.svet24.si');

    $site = Site::query()->where('hostname', 'vestnik.svet24.si')->sole();

    expect($result)->toMatchArray(['outcome' => 'needs_review', 'message' => 'Backend not assigned (suggested: web02)'])
        ->and($site->front_service_id)->toBe($this->varnish->id)
        ->and($site->server_id)->toBeNull();
});

test('a new domain is created and a site without any suggestion is left for review', function () {
    $result = importUrl('shop.oglasi24.si');

    expect($result)->toMatchArray(['outcome' => 'needs_review', 'message' => 'Backend not assigned'])
        ->and(Domain::query()->where('name', 'oglasi24.si')->exists())->toBeTrue();
});

test('duplicates and non-URLs are skipped', function (string $url, string $message) {
    expect(importUrl($url))->toMatchArray(['outcome' => 'skipped', 'message' => $message])
        ->and(Site::query()->count())->toBe(1);
})->with([
    'existing site' => ['https://svet24.si/', 'Already a site'],
    'IP address' => ['192.0.2.10', "Enter the site's hostname, not an IP address."],
]);

test('the import page renders', function () {
    $this->actingAs($this->admin)
        ->get(route('sites.import'))
        ->assertInertia(fn (Assert $page) => $page->component('sites/Import'));
});

test('the import command reads a file and prints a summary', function () {
    $file = tempnam(sys_get_temp_dir(), 'urls');
    file_put_contents($file, "# Media24 sites\ndirect.svet24.si\n\nvestnik.svet24.si\nsvet24.si\ndirect.svet24.si\noglasi24.si\n");

    $this->artisan('sextant:import', ['file' => $file])
        ->expectsOutputToContain('2 created, 1 need review, 1 skipped.')
        ->assertSuccessful();

    expect(Site::query()->pluck('hostname')->sort()->values()->all())
        ->toBe(['direct.svet24.si', 'oglasi24.si', 'svet24.si', 'vestnik.svet24.si']);

    unlink($file);
});

test('the import command refuses a missing file', function () {
    $this->artisan('sextant:import', ['file' => '/nonexistent/urls.txt'])->assertFailed();
});

test('a new application is created once and every imported site joins it', function () {
    importUrl('direct.svet24.si', ['application_name' => 'Backend']);
    importUrl('shop.oglasi24.si', ['application_name' => 'Backend']);

    $backend = Application::query()->sole();

    expect($backend->name)->toBe('Backend')
        ->and($backend->sites()->pluck('hostname')->sort()->values()->all())->toBe(['direct.svet24.si', 'shop.oglasi24.si']);
});

test('an existing site joins the chosen application', function () {
    $backend = Application::factory()->create(['name' => 'Backend']);

    expect(importUrl('svet24.si', ['application_id' => $backend->id]))->toMatchArray(['outcome' => 'skipped', 'message' => 'Already a site; added to Backend'])
        ->and(importUrl('svet24.si', ['application_id' => $backend->id])['message'])->toBe('Already a site in Backend')
        ->and(Site::query()->where('hostname', 'svet24.si')->sole()->application_id)->toBe($backend->id);
});

test('the backend chosen for all fills in only where detection is unsure', function () {
    $web05 = Server::factory()->create(['name' => 'web05']);

    $unsure = importUrl('vestnik.svet24.si', ['server_id' => $web05->id]);
    $detected = importUrl('direct.svet24.si', ['server_id' => $web05->id]);

    expect($unsure)->toMatchArray(['outcome' => 'created', 'message' => 'Varnish → web05 (chosen for all)'])
        ->and(Site::query()->where('hostname', 'vestnik.svet24.si')->sole()->server_id)->toBe($web05->id)
        ->and($detected['message'])->toBe('web02')
        ->and(Site::query()->where('hostname', 'direct.svet24.si')->sole()->server_id)->toBe($this->web02->id);
});

test('an application or backend that is deleted is refused', function (string $field) {
    $deleted = ['application_id' => tap(Application::factory()->create())->delete()->id, 'server_id' => tap(Server::factory()->create())->delete()->id][$field];

    $this->actingAs($this->admin)
        ->postJson(route('sites.import.store'), ['url' => 'direct.svet24.si', $field => $deleted])
        ->assertJsonValidationErrors($field);
})->with(['application_id', 'server_id']);
