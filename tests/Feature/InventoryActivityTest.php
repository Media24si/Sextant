<?php

use App\Enums\ActivityCategory;
use App\Enums\ChoiceList;
use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Choice;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Models\User;

// MySQL's JSON columns reorder object keys, so `properties` are compared with toEqual.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ana Novak']);
    $this->actingAs($this->admin);
    Activity::query()->delete();
});

/**
 * @return list<string>
 */
function activityTypes(): array
{
    return Activity::query()->orderBy('id')->pluck('type')->all();
}

test('admin changes are recorded with the admin as the causer', function () {
    $server = Server::factory()->create(['name' => 'web01', 'provider' => 'Arnes']);
    $server->update(['provider' => 'Hetzner', 'notes' => 'Rack 3']);
    $server->delete();
    $server->restore();

    expect(activityTypes())->toBe(['server.created', 'server.updated', 'server.deleted', 'server.restored']);

    $updated = Activity::query()->where('type', 'server.updated')->sole();

    expect($updated->category)->toBe(ActivityCategory::Inventory)
        ->and($updated->user_id)->toBe($this->admin->id)
        ->and($updated->server_id)->toBe($server->id)
        ->and($updated->subject_type)->toBe('server')
        ->and($updated->summary)->toBe('Server web01 changed: provider, notes')
        ->and($updated->properties)->toEqual([
            'provider' => ['old' => 'Arnes', 'new' => 'Hetzner'],
            'notes' => ['old' => null, 'new' => 'Rack 3'],
        ]);
});

test('system writes with saveQuietly never appear', function () {
    $server = Server::factory()->create();
    Activity::query()->delete();

    $server->forceFill(['status' => Status::Error, 'status_changed_at' => now(), 'os_reported' => 'Ubuntu 24.04.1 LTS'])->saveQuietly();

    expect(Activity::query()->count())->toBe(0);
});

test('system-maintained columns are left out of admin updates', function () {
    $domain = Domain::factory()->create();
    Activity::query()->delete();

    $domain->forceFill(['expires_at' => '2027-07-18', 'registrar' => 'INFONET MEDIA'])->save();
    expect(Activity::query()->count())->toBe(0);

    $domain->update(['notes' => 'Main brand']);
    expect(Activity::query()->sole()->properties)->toEqual(['notes' => ['old' => null, 'new' => 'Main brand']]);
});

test('a new versions token is recorded without its value', function () {
    $server = Server::factory()->create(['name' => 'web02']);
    Activity::query()->delete();

    $server->forceFill(['versions_token' => 'sxt_super_secret_value', 'versions_token_last4' => 'alue'])->save();

    $activity = Activity::query()->sole();

    expect($activity->type)->toBe('server.versions_token_rotated')
        ->and($activity->properties)->toBeNull()
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('sxt_super_secret_value')
        ->not->toContain('alue');
});

test('address changes are recorded on the server', function () {
    $server = Server::factory()->create(['name' => 'web02']);
    $address = ServerAddress::factory()->for($server)->create(['ip' => '192.0.2.12', 'is_primary' => true]);
    $address->update(['ip' => '192.0.2.13']);
    $address->delete();

    expect(Activity::query()->where('subject_type', 'server')->where('type', 'like', 'server.address%')->pluck('summary')->all())->toBe([
        'web02: address 192.0.2.12 added (primary)',
        'web02: address 192.0.2.12 removed',
        'web02: address 192.0.2.13 added (primary)',
        'web02: address 192.0.2.13 removed',
    ]);
});

test('changing backup locations records the old and new lists', function () {
    $server = Server::factory()->create(['name' => 'web02']);
    [$snapshots, $backupServer] = Choice::ofList(ChoiceList::BackupLocation)->take(2)->get()->all();
    Activity::query()->delete();

    $server->syncBackupLocations([$snapshots->id]);
    $server->syncBackupLocations([$snapshots->id]);
    $server->syncBackupLocations([$snapshots->id, $backupServer->id]);

    expect(Activity::query()->pluck('summary')->all())->toBe([
        'web02 backups: Provider snapshots (was none)',
        'web02 backups: Provider snapshots, Backup server (was Provider snapshots)',
    ]);
});

test('a serving path change gets its own type and the readable paths', function () {
    $web01 = Server::factory()->create(['name' => 'web01']);
    $web02 = Server::factory()->create(['name' => 'web02']);
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    $site = Site::factory()->create(['hostname' => 'vestnik.svet24.si', 'server_id' => $web01->id]);
    Activity::query()->delete();

    $site->update(['front_service_id' => $varnish->id, 'server_id' => $web02->id, 'description' => 'Local news']);

    expect(activityTypes())->toBe(['site.path_changed', 'site.updated']);

    $path = Activity::query()->where('type', 'site.path_changed')->sole();

    expect($path->summary)->toBe('vestnik.svet24.si serving path: Varnish → web02 (was direct → web01)')
        ->and($path->server_id)->toBe($web02->id)
        ->and($path->properties['path'])->toEqual(['old' => 'direct → web01', 'new' => 'Varnish → web02']);
});

test('users: created, deleted and role changes only, never passwords', function () {
    $user = User::factory()->reader()->create(['name' => 'Marko Kranjc']);
    $user->update(['name' => 'Marko K.', 'password' => 'a-new-password']);
    $user->role = UserRole::Admin;
    $user->save();
    $user->delete();

    expect(Activity::query()->pluck('summary')->all())->toBe([
        'User Marko Kranjc added (reader)',
        'Marko K. is now admin (was reader)',
        'User Marko K. deleted',
    ])->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('a-new-password');
});

test('changes without a signed-in user are recorded as Sextant', function () {
    auth()->logout();

    Domain::factory()->create();

    expect(Activity::query()->sole()->user_id)->toBeNull();
});
