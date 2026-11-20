<?php

use App\Enums\ChoiceList;
use App\Enums\ExternalServiceKind;
use App\Enums\RangeSource;
use App\Enums\Status;
use App\Models\Choice;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('the lists and Cloudflare are seeded by the migrations', function () {
    expect(Choice::ofList(ChoiceList::Os)->pluck('label')->all())->toBe([
        'Ubuntu 22.04 LTS', 'Ubuntu 24.04 LTS', 'Ubuntu 26.04 LTS', 'Debian 12', 'Debian 13',
        'Windows Server 2019', 'Windows Server 2022', 'Windows Server 2025', 'Other',
    ])->and(Choice::ofList(ChoiceList::BackupLocation)->count())->toBe(4);

    $cloudflare = ExternalService::query()->sole();

    expect($cloudflare->name)->toBe('Cloudflare')
        ->and($cloudflare->kind)->toBe(ExternalServiceKind::Cdn)
        ->and($cloudflare->ranges_source)->toBe(RangeSource::Cloudflare)
        ->and($cloudflare->cname_suffixes)->toBe(['.cdn.cloudflare.net'])
        ->and($cloudflare->response_headers)->toBe(['cf-ray']);
});

test('names are unique among live rows only', function () {
    $server = Server::factory()->create(['name' => 'web01']);

    expect(fn () => Server::factory()->create(['name' => 'web01']))
        ->toThrow(UniqueConstraintViolationException::class);

    $server->delete();
    Server::factory()->create(['name' => 'web01']);

    expect(Server::withTrashed()->where('name', 'web01')->count())->toBe(2);
});

test('hostnames are stored lowercase punycode and shown in Unicode', function () {
    $domain = Domain::factory()->create(['name' => 'ŠOLA.si.']);
    $site = Site::factory()->for($domain)->create(['hostname' => 'WWW.šola.si']);

    expect($domain->name)->toBe('xn--ola-zza.si')
        ->and($domain->display_name)->toBe('šola.si')
        ->and($site->hostname)->toBe('www.xn--ola-zza.si')
        ->and($site->display_hostname)->toBe('www.šola.si');
});

test('a site url is scheme, hostname and an optional port', function () {
    $site = Site::factory()->make(['scheme' => 'https', 'hostname' => 'svet24.si', 'port' => null]);

    expect($site->url)->toBe('https://svet24.si');

    $site->forceFill(['scheme' => 'http', 'port' => 8080]);

    expect($site->url)->toBe('http://svet24.si:8080');
});

test('the serving path is the front service, then the backend', function () {
    $varnish = ExternalService::factory()->create(['name' => 'Varnish']);
    $server = Server::factory()->create(['name' => 'web02']);

    $site = Site::factory()->create(['front_service_id' => $varnish->id, 'server_id' => $server->id]);
    expect($site->servingPath()->toArray())->toBe(['front' => 'Varnish', 'backend' => 'web02']);

    $direct = Site::factory()->create(['server_id' => $server->id]);
    expect($direct->servingPath()->toArray())->toBe(['front' => null, 'backend' => 'web02']);

    $unassigned = Site::factory()->create();
    expect($unassigned->servingPath()->toArray())->toBe(['front' => null, 'backend' => null]);
});

test('addresses are stored canonically with their version', function () {
    $address = ServerAddress::factory()->create(['ip' => '2001:DB8:0:0::0001']);
    $v4 = ServerAddress::factory()->create(['ip' => ' 192.0.2.12 ']);

    expect($address->ip)->toBe('2001:db8::1')
        ->and($address->version)->toBe(6)
        ->and($v4->ip)->toBe('192.0.2.12')
        ->and($v4->version)->toBe(4);
});

test('the versions token is encrypted at rest and never serialised', function () {
    $server = Server::factory()->create();
    $server->forceFill(['versions_token' => 'sxt_secret'])->save();

    expect(DB::table('servers')->value('versions_token'))->not->toContain('sxt_secret')
        ->and($server->fresh()->versions_token)->toBe('sxt_secret')
        ->and($server->toArray())->not->toHaveKey('versions_token');
});

test('new resources start unknown', function () {
    expect(Server::factory()->create()->fresh()->status)->toBe(Status::Unknown)
        ->and(Domain::factory()->create()->fresh()->status)->toBe(Status::Unknown)
        ->and(Site::factory()->create()->fresh()->status)->toBe(Status::Unknown);
});

test('things in use say so', function () {
    $os = Choice::ofList(ChoiceList::Os)->firstOrFail();
    $backup = Choice::ofList(ChoiceList::BackupLocation)->firstOrFail();
    expect($os->isInUse())->toBeFalse()->and($backup->isInUse())->toBeFalse();

    $server = Server::factory()->create(['os_choice_id' => $os->id]);
    $server->backupLocations()->attach($backup);
    expect($os->isInUse())->toBeTrue()->and($backup->isInUse())->toBeTrue();

    $varnish = ExternalService::factory()->create();
    $domain = Domain::factory()->create();
    expect($varnish->isInUse())->toBeFalse()->and($domain->hasLiveSites())->toBeFalse();

    $site = Site::factory()->for($domain)->create(['front_service_id' => $varnish->id]);
    expect($varnish->isInUse())->toBeTrue()->and($domain->hasLiveSites())->toBeTrue();

    $site->delete();
    expect($varnish->isInUse())->toBeFalse()->and($domain->hasLiveSites())->toBeFalse();
});
