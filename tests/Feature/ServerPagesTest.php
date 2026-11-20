<?php

use App\Enums\ChoiceList;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Choice;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->reader = User::factory()->reader()->create();
});

function serverPayload(array $overrides = []): array
{
    return [
        'name' => 'web06',
        'hostname' => 'web06.media24.si',
        'provider' => 'Hetzner',
        'os_choice_id' => Choice::ofList(ChoiceList::Os)->value('id'),
        'notes' => null,
        'addresses' => [
            ['ip' => '198.51.100.23', 'is_primary' => true],
            ['ip' => '2001:db8:24::23', 'is_primary' => false],
        ],
        'backup_location_ids' => Choice::ofList(ChoiceList::BackupLocation)->take(2)->pluck('id')->all(),
        ...array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $overrides),
    ];
}

test('readers see the servers list with addresses, sites and backups', function () {
    $server = Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web02']);
    $server->backupLocations()->attach(Choice::ofList(ChoiceList::BackupLocation)->first());
    Site::factory()->count(2)->create(['server_id' => $server->id]);
    Server::factory()->create(['name' => 'web01']);

    $this->actingAs($this->reader)
        ->get(route('servers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/Index')
            ->has('rows.data', 2)
            ->where('rows.data.0.name', 'web01')
            ->where('rows.data.0.backup_locations', [])
            ->where('rows.data.1.name', 'web02')
            ->where('rows.data.1.primary_ip', '192.0.2.12')
            ->where('rows.data.1.sites_count', 2)
            ->where('rows.data.1.backup_locations', ['Provider snapshots'])
            ->where('counts', ['all' => 2, 'problems' => 0, 'no_backup' => 1, 'deleted' => 0])
            ->where('nav.servers', 2));
});

test('the list filters problems, missing backups and deleted servers, and sorts by sites', function () {
    Server::factory()->create(['name' => 'web01'])->forceFill(['status' => Status::Error])->saveQuietly();
    $backedUp = Server::factory()->create(['name' => 'web02']);
    $backedUp->backupLocations()->attach(Choice::ofList(ChoiceList::BackupLocation)->first());
    Site::factory()->create(['server_id' => $backedUp->id]);
    Server::factory()->create(['name' => 'old01'])->delete();

    $names = fn (array $query) => collect($this->actingAs($this->reader)->get(route('servers.index', $query))
        ->viewData('page')['props']['rows']['data'])->pluck('name')->all();

    expect($names(['filter' => ['show' => 'problems']]))->toBe(['web01'])
        ->and($names(['filter' => ['show' => 'no_backup']]))->toBe(['web01'])
        ->and($names(['filter' => ['show' => 'deleted']]))->toBe(['old01'])
        ->and($names(['sort' => '-sites_count']))->toBe(['web02', 'web01']);
});

test('the server page shows how traffic reaches it, its sites and its changes', function () {
    $this->actingAs($this->admin);
    $server = Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web02']);
    $server->forceFill(['versions_token' => 'sxt_secret'])->save();
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    Site::factory()->create(['hostname' => 'svet24.si', 'server_id' => $server->id, 'front_service_id' => $varnish->id]);
    Site::factory()->create(['hostname' => 'podjetje.svet24.si', 'server_id' => $server->id, 'front_service_id' => $varnish->id]);
    Site::factory()->create(['hostname' => 'media24.si', 'server_id' => $server->id]);

    $response = $this->actingAs($this->reader)->get(route('servers.show', $server));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('servers/Show')
        ->where('server.name', 'web02')
        ->where('server.addresses.0.ip', '192.0.2.12')
        ->where('server.addresses.0.is_primary', true)
        ->missing('server.versions_token')
        ->where('traffic', [
            ['name' => 'Varnish', 'kind' => 'proxy', 'sites_count' => 2, 'first_site' => 'podjetje.svet24.si'],
            ['name' => 'Direct', 'kind' => null, 'sites_count' => 1, 'first_site' => 'media24.si'],
        ])
        ->where('sites_total', 3)
        ->where('sites.0.hostname', 'media24.si')
        ->where('sites.0.serving_path', ['front' => null, 'backend' => 'web02'])
        ->where('activity', fn ($activity) => collect($activity)->pluck('type')->contains('server.versions_token_rotated')
            && collect($activity)->pluck('type')->contains('site.created')));

    expect($response->getContent())->not->toContain('sxt_secret');
});

test('an admin adds a server with addresses and backup locations', function () {
    $this->actingAs($this->admin)
        ->post(route('servers.store'), serverPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('servers.show', Server::query()->sole()));

    $server = Server::query()->with(['addresses', 'backupLocations', 'os'])->sole();

    expect($server->name)->toBe('web06')
        ->and($server->os?->list)->toBe(ChoiceList::Os)
        ->and($server->addresses->map->only('ip', 'version', 'is_primary')->all())->toBe([
            ['ip' => '198.51.100.23', 'version' => 4, 'is_primary' => true],
            ['ip' => '2001:db8:24::23', 'version' => 6, 'is_primary' => false],
        ])
        ->and($server->backupLocations->pluck('label')->all())->toBe(['Provider snapshots', 'Backup server'])
        ->and(Activity::query()->where('server_id', $server->id)->pluck('type')->all())->toBe([
            'server.created', 'server.address_added', 'server.address_added', 'server.backup_locations_changed',
        ]);
});

test('server input is validated', function (array $overrides, string $error) {
    Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web02']);
    ExternalService::factory()->create(['ranges' => ['203.0.113.0/24']]);

    $this->actingAs($this->admin)
        ->post(route('servers.store'), serverPayload($overrides))
        ->assertSessionHasErrors($error);

    expect(Server::query()->count())->toBe(1);
})->with([
    'name taken' => [['name' => 'web02'], 'name'],
    'bad hostname' => [['hostname' => 'not a host'], 'hostname'],
    'no addresses' => [['addresses' => []], 'addresses'],
    'two primaries' => [['addresses' => [['ip' => '198.51.100.1', 'is_primary' => true], ['ip' => '198.51.100.2', 'is_primary' => true]]], 'addresses'],
    'no primary' => [['addresses' => [['ip' => '198.51.100.1', 'is_primary' => false]]], 'addresses'],
    'not an ip' => [['addresses' => [['ip' => '300.1.1.1', 'is_primary' => true]]], 'addresses.0.ip'],
    'same ip twice' => [['addresses' => [['ip' => '198.51.100.1', 'is_primary' => true], ['ip' => '198.51.100.1', 'is_primary' => false]]], 'addresses.1.ip'],
    'another server\'s ip' => [['addresses' => [['ip' => '192.0.2.12', 'is_primary' => true]]], 'addresses.0.ip'],
    'external service ip' => [['addresses' => [['ip' => '203.0.113.9', 'is_primary' => true]]], 'addresses.0.ip'],
    'os from the wrong list' => [['os_choice_id' => fn () => Choice::ofList(ChoiceList::BackupLocation)->value('id')], 'os_choice_id'],
]);

test('editing keeps unchanged addresses and moves the primary', function () {
    $this->actingAs($this->admin)->post(route('servers.store'), serverPayload());
    $server = Server::query()->sole();
    $keptId = $server->addresses()->where('ip', '2001:db8:24::23')->value('id');

    $this->put(route('servers.update', $server), serverPayload([
        'name' => 'web06-new',
        'addresses' => [
            ['ip' => '2001:db8:24::23', 'is_primary' => true],
            ['ip' => '198.51.100.99', 'is_primary' => false],
        ],
        'backup_location_ids' => [],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('servers.show', $server));

    $server->refresh()->load(['addresses', 'backupLocations']);

    expect($server->name)->toBe('web06-new')
        ->and($server->addresses->map->only('id', 'ip', 'is_primary')->all())->toBe([
            ['id' => $keptId, 'ip' => '2001:db8:24::23', 'is_primary' => true],
            ['id' => $server->addresses->last()->id, 'ip' => '198.51.100.99', 'is_primary' => false],
        ])
        ->and($server->backupLocations)->toBeEmpty();
});

test('a server can keep its own address when edited', function () {
    $this->actingAs($this->admin)->post(route('servers.store'), serverPayload());

    $this->put(route('servers.update', Server::query()->sole()), serverPayload(['notes' => 'Rack 3']))
        ->assertSessionHasNoErrors();
});

test('a server that is still a backend is not deleted', function () {
    $server = Server::factory()->create(['name' => 'web02']);
    Site::factory()->count(2)->create(['server_id' => $server->id]);

    $this->actingAs($this->admin)
        ->from(route('servers.show', $server))
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('servers.show', $server))
        ->assertInertiaFlash('toast.message', 'web02 is the backend of 2 sites. Move them to another server first.');

    expect($server->fresh()->trashed())->toBeFalse();
});

test('a server is soft-deleted, still viewable, and can be restored', function () {
    $server = Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web02']);

    $this->actingAs($this->admin)
        ->delete(route('servers.destroy', $server))
        ->assertRedirect(route('servers.index'));

    expect($server->fresh()->trashed())->toBeTrue();

    $this->get(route('servers.show', $server))
        ->assertInertia(fn (Assert $page) => $page->where('server.deleted', true));

    $this->post(route('servers.restore', $server))
        ->assertRedirect(route('servers.show', $server));

    expect($server->fresh()->trashed())->toBeFalse();
});

test('restoring is refused when the name or an address is taken again', function () {
    $server = Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web02']);
    $server->delete();
    Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web03']);

    $this->actingAs($this->admin)
        ->from(route('servers.show', $server))
        ->post(route('servers.restore', $server))
        ->assertInertiaFlash('toast.message', "Can't restore web02: one of its addresses now belongs to another server.");

    expect($server->fresh()->trashed())->toBeTrue();
});

test('the add and edit forms get the lists', function () {
    $server = Server::factory()->withPrimaryAddress('192.0.2.12')->create();

    $this->actingAs($this->admin)->get(route('servers.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/Form')
            ->where('form.id', null)
            ->where('form.addresses', [['ip' => '', 'is_primary' => true]])
            ->has('os_choices', 9)
            ->has('backup_choices', 4));

    $this->get(route('servers.edit', $server))
        ->assertInertia(fn (Assert $page) => $page
            ->where('form.id', $server->id)
            ->where('form.addresses', [['ip' => '192.0.2.12', 'is_primary' => true]]));
});
