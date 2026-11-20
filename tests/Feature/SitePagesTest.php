<?php

use App\Enums\Status;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reader = User::factory()->reader()->create();
    $this->domain = Domain::factory()->create(['name' => 'svet24.si']);
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    $this->varnish = ExternalService::factory()->create(['name' => 'Varnish']);
});

function sitePayload(array $overrides = []): array
{
    return [
        'domain_id' => test()->domain->id,
        'hostname' => 'podjetje.svet24.si',
        'scheme' => 'https',
        'port' => '',
        'front_service_id' => test()->varnish->id,
        'server_id' => test()->web02->id,
        'description' => 'Business section',
        'github_url' => 'https://github.com/media24/podjetje/',
        'notes' => null,
        ...array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $overrides),
    ];
}

test('readers see the sites list with domain, serving path and status', function () {
    Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si', 'server_id' => $this->web02->id, 'front_service_id' => $this->varnish->id]);
    Site::factory()->for($this->domain)->create(['hostname' => 'njena.svet24.si']);

    $this->actingAs($this->reader)
        ->get(route('sites.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Index')
            ->where('rows.data.0.hostname', 'njena.svet24.si')
            ->where('rows.data.0.serving_path', ['front' => null, 'backend' => null])
            ->where('rows.data.1.domain', 'svet24.si')
            ->where('rows.data.1.serving_path', ['front' => 'Varnish', 'backend' => 'web02'])
            ->where('counts', ['all' => 2, 'problems' => 0, 'needs_review' => 1, 'deleted' => 0])
            ->has('front_services', 2)
            ->has('servers', 1));
});

test('the sites list filters by status, front service, backend and text', function (array $filter, array $expected) {
    $cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();
    Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si', 'server_id' => $this->web02->id, 'front_service_id' => $this->varnish->id]);
    Site::factory()->for($this->domain)->create(['hostname' => 'kviz.svet24.si', 'server_id' => $this->web02->id, 'front_service_id' => $cloudflare->id, 'description' => 'Quiz'])
        ->forceFill(['status' => Status::Warning])->saveQuietly();
    Site::factory()->for($this->domain)->create(['hostname' => 'staro.svet24.si']);

    $filter = array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $filter);

    $hostnames = collect($this->actingAs($this->reader)->get(route('sites.index', ['filter' => $filter]))
        ->viewData('page')['props']['rows']['data'])->pluck('hostname')->all();

    expect($hostnames)->toBe($expected);
})->with([
    'problems' => [['show' => 'problems'], ['kviz.svet24.si']],
    'needs review' => [['show' => 'needs_review'], ['staro.svet24.si']],
    'direct' => [['front' => 'direct'], ['staro.svet24.si']],
    'behind varnish' => [['front' => fn () => (string) test()->varnish->id], ['svet24.si']],
    'no backend' => [['backend' => 'none'], ['staro.svet24.si']],
    'on web02' => [['backend' => fn () => (string) test()->web02->id], ['kviz.svet24.si', 'svet24.si']],
    'search' => [['search' => 'quiz'], ['kviz.svet24.si']],
]);

test('an admin adds a site by hand', function () {
    $this->actingAs($this->admin)
        ->post(route('sites.store'), sitePayload(['hostname' => 'Podjetje.Svet24.si.']))
        ->assertSessionHasNoErrors();

    $site = Site::query()->sole();

    expect($site->hostname)->toBe('podjetje.svet24.si')
        ->and($site->port)->toBeNull()
        ->and($site->servingPath()->toArray())->toBe(['front' => 'Varnish', 'backend' => 'web02'])
        ->and($site->github_url)->toBe('https://github.com/media24/podjetje')
        ->and(Activity::query()->where('type', 'site.created')->exists())->toBeTrue();
});

test('site input is validated', function (array $overrides, string $error) {
    Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si']);
    $other = Domain::factory()->create(['name' => 'media24.si']);

    $this->actingAs($this->admin)
        ->post(route('sites.store'), sitePayload($overrides))
        ->assertSessionHasErrors($error);
})->with([
    'hostname taken' => [['hostname' => 'svet24.si'], 'hostname'],
    'outside the domain' => [['hostname' => 'media24.si'], 'hostname'],
    'lookalike domain' => [['hostname' => 'notsvet24.si'], 'hostname'],
    'not a hostname' => [['hostname' => 'https://podjetje.svet24.si'], 'hostname'],
    'bad scheme' => [['scheme' => 'ftp'], 'scheme'],
    'bad port' => [['port' => '70000'], 'port'],
    'deleted backend' => [['server_id' => fn () => tap(Server::factory()->create())->delete()->id], 'server_id'],
    'not a repository' => [['github_url' => 'https://github.com/media24'], 'github_url'],
]);

test('an admin edits the serving path and it reaches the change feed', function () {
    $site = Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si', 'server_id' => $this->web02->id]);

    $this->actingAs($this->admin)->get(route('sites.edit', $site))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Form')
            ->where('form.hostname', 'svet24.si')
            ->where('form.server_id', $this->web02->id));

    $this->put(route('sites.update', $site), sitePayload(['hostname' => 'svet24.si', 'port' => '8443']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('sites.show', $site));

    expect($site->fresh()->port)->toBe(8443)
        ->and(Activity::query()->where('type', 'site.path_changed')->sole()->summary)
        ->toBe('svet24.si serving path: Varnish → web02 (was direct → web02)');
});

test('the add form can start from a domain', function () {
    $this->actingAs($this->admin)
        ->get(route('sites.create', ['domain' => $this->domain->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('form.domain_id', $this->domain->id)
            ->where('form.hostname', 'svet24.si')
            ->has('domains', 1));
});

test('the site page shows its path and changes; delete and restore', function () {
    $this->actingAs($this->admin);
    $site = Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si', 'server_id' => $this->web02->id, 'port' => 8443]);

    $this->actingAs($this->reader)->get(route('sites.show', $site))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Show')
            ->where('site.url', 'https://svet24.si:8443')
            ->where('site.serving_path', ['front' => null, 'backend' => 'web02'])
            ->where('activity.0.type', 'site.created'));

    $this->actingAs($this->admin)->delete(route('sites.destroy', $site))
        ->assertRedirect(route('domains.show', $this->domain));
    expect($site->fresh()->trashed())->toBeTrue();

    $this->post(route('sites.restore', $site))->assertRedirect(route('sites.show', $site));
    expect($site->fresh()->trashed())->toBeFalse();
});

test('a site is not restored when its hostname is taken again', function () {
    $site = Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si']);
    $site->delete();
    Site::factory()->for($this->domain)->create(['hostname' => 'svet24.si']);

    $this->actingAs($this->admin)
        ->from(route('sites.show', $site))
        ->post(route('sites.restore', $site))
        ->assertInertiaFlash('toast.message', "Can't restore svet24.si: it was added again.");
});
