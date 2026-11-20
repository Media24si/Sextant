<?php

use App\Enums\ChoiceList;
use App\Models\Choice;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->reader = User::factory()->reader()->create();

    $ubuntu = Choice::ofList(ChoiceList::Os)->where('label', 'Ubuntu 24.04 LTS')->sole();
    $this->web02 = Server::factory()->withPrimaryAddress('192.0.2.12')->create(['name' => 'web02', 'hostname' => 'web02.media24.si', 'os_choice_id' => $ubuntu->id]);
    $this->db01 = Server::factory()->withPrimaryAddress('192.0.2.31')->create(['name' => 'db01', 'hostname' => 'db01.media24.si']);
    $this->db01->forceFill(['os_reported' => 'Debian GNU/Linux 12 (bookworm)'])->saveQuietly();

    $svet = Domain::factory()->create(['name' => 'svet24.si']);
    Site::factory()->for($svet)->create(['hostname' => 'podjetje.svet24.si', 'description' => 'Business section', 'server_id' => $this->web02->id, 'front_service_id' => ExternalService::query()->value('id')]);
    $sola = Domain::factory()->create(['name' => 'šola.si']);
    Site::factory()->for($sola)->create(['hostname' => 'www.šola.si']);
});

/**
 * @return list<array<string, mixed>>
 */
function search(string $q): array
{
    return test()->actingAs(test()->reader)->getJson(route('search', ['q' => $q]))->assertOk()->json('results');
}

test('servers are found by name, hostname, address and OS', function (string $q) {
    expect(collect(search($q))->where('type', 'server')->pluck('label')->all())->toBe(['web02']);
})->with(['web02', 'WEB02.media24', '192.0.2.12', 'ubuntu 24']);

test('the reported OS is searched too', function () {
    expect(collect(search('bookworm'))->pluck('label')->all())->toBe(['db01']);
});

test('a server result carries its status, context and page', function () {
    expect(search('web02')[0])->toBe([
        'type' => 'server',
        'id' => $this->web02->id,
        'label' => 'web02',
        'context' => '192.0.2.12 · Ubuntu 24.04 LTS · 1 site',
        'status' => 'unknown',
        'url' => route('servers.show', $this->web02, false),
    ]);
});

test('results are grouped by type: servers, external services, domains, sites', function () {
    expect(collect(search('svet24'))->pluck('type')->all())->toBe(['domain', 'site'])
        ->and(collect(search('cloud'))->pluck('type')->all())->toBe(['external_service']);
});

test('sites are found by hostname and description', function () {
    expect(collect(search('business'))->pluck('label')->all())->toBe(['podjetje.svet24.si'])
        ->and(search('business')[0]['context'])->toBe('Business section · Cloudflare → web02');
});

test('Unicode hostnames are found in either form and shown in Unicode', function (string $q) {
    expect(collect(search($q))->pluck('label')->all())->toBe(['šola.si', 'www.šola.si']);
})->with(['šola', 'xn--ola-zza']);

test('short queries return nothing and LIKE wildcards are literal', function () {
    expect(search('w'))->toBe([])
        ->and(search('%%'))->toBe([])
        ->and(search('__'))->toBe([]);
});

test('at most 20 results come back', function () {
    Server::factory()->count(12)->sequence(fn ($sequence) => ['name' => 'app'.$sequence->index])->create();
    Domain::factory()->count(12)->sequence(fn ($sequence) => ['name' => 'app'.$sequence->index.'.si'])->create();
    Site::factory()->count(12)->sequence(fn ($sequence) => ['hostname' => 'app.example'.$sequence->index.'.si'])->create();

    expect(search('app'))->toHaveCount(20);
});

test('guests can\'t search', function () {
    $this->getJson(route('search', ['q' => 'web02']))->assertUnauthorized();
});
