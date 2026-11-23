<?php

use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use App\Models\Application;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('readers see the external services with ranges and sites', function () {
    $varnish = ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80', '203.0.113.81']]);
    Site::factory()->count(3)->create(['front_service_id' => $varnish->id]);

    $this->actingAs(User::factory()->reader()->create())
        ->get(route('external-services.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('external-services/Index')
            ->has('rows.data', 2)
            ->where('rows.data.0.name', 'Cloudflare')
            ->where('rows.data.0.ranges_source', 'cloudflare')
            ->where('rows.data.1.name', 'Varnish')
            ->where('rows.data.1.ranges_count', 2)
            ->where('rows.data.1.sites_count', 3));
});

test('the service page shows where its traffic goes and what goes down with it', function () {
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    $web02 = Server::factory()->create(['name' => 'web02']);
    $svet24 = Domain::factory()->create(['name' => 'svet24.si']);
    $app = Application::factory()->create(['name' => 'Svet24']);
    Site::factory()->for($svet24)->create(['hostname' => 'svet24.si', 'front_service_id' => $varnish->id, 'server_id' => $web02->id, 'application_id' => $app->id]);
    Site::factory()->for($svet24)->create(['hostname' => 'podjetje.svet24.si', 'front_service_id' => $varnish->id, 'server_id' => $web02->id]);
    Site::factory()->for($svet24)->create(['hostname' => 'ekipa.svet24.si', 'front_service_id' => $varnish->id]);
    Site::factory()->for($svet24)->create(['hostname' => 'direct.svet24.si', 'server_id' => $web02->id, 'application_id' => $app->id]);

    $this->actingAs(User::factory()->reader()->create())
        ->get(route('external-services.show', $varnish))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('external-services/Show')
            ->where('service.name', 'Varnish')
            ->where('backends', [
                ['server_id' => $web02->id, 'server' => 'web02', 'sites_count' => 2, 'first_site' => 'podjetje.svet24.si'],
                ['server_id' => null, 'server' => null, 'sites_count' => 1, 'first_site' => 'ekipa.svet24.si'],
            ])
            ->has('sites', 3)
            ->where('radius.sites', 3)
            ->where('radius.applications', [['id' => $app->id, 'name' => 'Svet24', 'affected' => 1, 'total' => 2]])
            ->where('radius.domains', [['id' => $svet24->id, 'name' => 'svet24.si', 'affected' => 3, 'total' => 4]])
            ->where('radius.hostnames', ['ekipa.svet24.si', 'podjetje.svet24.si', 'svet24.si']));
});

test('an admin adds a service; textarea lines become clean lists', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('external-services.store'), [
            'name' => 'Varnish',
            'kind' => 'proxy',
            'ranges' => "203.0.113.80\n 2001:db8:80::/48 \n\n",
            'ranges_source' => null,
            'cname_suffixes' => "Varnish.Example.NET.\n.cdn.example.net",
            'response_headers' => 'X-Varnish, Via',
            'notes' => 'In front of svet24',
        ])
        ->assertSessionHasNoErrors();

    $service = ExternalService::query()->where('name', 'Varnish')->sole();
    $response->assertRedirect(route('external-services.show', $service));

    expect($service->kind)->toBe(ExternalServiceKind::Proxy)
        ->and($service->ranges)->toBe(['203.0.113.80', '2001:db8:80::/48'])
        ->and($service->cname_suffixes)->toBe(['.varnish.example.net', '.cdn.example.net'])
        ->and($service->response_headers)->toBe(['x-varnish', 'via']);
});

test('external service input is validated', function (array $input, string $error) {
    Server::factory()->withPrimaryAddress('192.0.2.12')->create();

    $this->actingAs($this->admin)
        ->post(route('external-services.store'), [
            'name' => 'Varnish',
            'kind' => 'proxy',
            ...$input,
        ])
        ->assertSessionHasErrors($error);
})->with([
    'name taken' => [['name' => 'Cloudflare'], 'name'],
    'unknown kind' => [['kind' => 'mirror'], 'kind'],
    'not a range' => [['ranges' => "203.0.113.80\nnot-an-ip"], 'ranges.1'],
    'prefix too long' => [['ranges' => '203.0.113.0/33'], 'ranges.0'],
    'covers our server' => [['ranges' => '192.0.2.0/24'], 'ranges'],
    'unknown source' => [['ranges_source' => 'akamai'], 'ranges_source'],
    'bad header' => [['response_headers' => 'x varnish'], 'response_headers.0'],
]);

test('an admin edits a service', function () {
    $service = ExternalService::factory()->create(['name' => 'Varnish']);

    $this->actingAs($this->admin)
        ->get(route('external-services.edit', $service))
        ->assertInertia(fn (Assert $page) => $page
            ->component('external-services/Form')
            ->where('form.name', 'Varnish'));

    $this->put(route('external-services.update', $service), [
        'name' => 'Varnish 2',
        'kind' => 'cdn',
        'ranges' => '198.51.100.0/24',
        'ranges_source' => 'fastly',
    ])->assertSessionHasNoErrors();

    expect($service->fresh())
        ->name->toBe('Varnish 2')
        ->ranges_source->toBe(RangeSource::Fastly)
        ->ranges->toBe(['198.51.100.0/24']);
});

test('a service in front of sites is not deleted; an unused one is, and comes back', function () {
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    $site = Site::factory()->create(['front_service_id' => $varnish->id]);

    $this->actingAs($this->admin)
        ->from(route('external-services.edit', $varnish))
        ->delete(route('external-services.destroy', $varnish))
        ->assertInertiaFlash('toast.message', 'Varnish is in front of 1 site. Change their serving path first.');
    expect($varnish->fresh()->trashed())->toBeFalse();

    $site->update(['front_service_id' => null]);
    $this->delete(route('external-services.destroy', $varnish))->assertRedirect(route('external-services.index'));
    expect($varnish->fresh()->trashed())->toBeTrue();

    $this->post(route('external-services.restore', $varnish))->assertRedirect(route('external-services.show', $varnish));
    expect($varnish->fresh()->trashed())->toBeFalse();
});
