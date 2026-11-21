<?php

use App\Jobs\RunMonitor;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Monitoring\NetworkHealth;
use App\Net\DnsResolver;
use App\Net\Fake\FakeTcpProbe;
use App\Net\TcpProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/* Default monitors (SPEC §4.4), the Phase 2 hook and §6.4 effects. */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function monitorsOf(Server|Domain|Site $resource): array
{
    return $resource->monitors()->orderBy('id')->get()
        ->map(fn (Monitor $monitor) => trim($monitor->checker.' '.$monitor->label))->all();
}

test('Add site attaches the site and domain defaults and runs them at once', function () {
    Queue::fake();

    $this->actingAs($this->admin)->post(route('sites.store'), [
        'hostname' => 'podjetje.svet24.si', 'scheme' => 'https', 'path' => '/novice', 'add_www' => false,
    ])->assertSessionHasNoErrors();

    $site = Site::query()->sole();

    expect(monitorsOf($site))->toBe(['site.http', 'site.ssl', 'site.path', 'site.redirect'])
        ->and($site->monitors()->where('checker', 'site.http')->first()->arguments)->toBe(['path' => '/novice'])
        ->and(monitorsOf($site->domain))->toBe(['domain.expiration', 'domain.nameservers'])
        ->and($site->monitors()->first()->state)->not->toBeNull();
    Queue::assertPushed(RunMonitor::class, 6);
});

test('an http site gets no certificate check', function () {
    Queue::fake();

    $this->actingAs($this->admin)->post(route('sites.store'), ['hostname' => 'svet24.si', 'scheme' => 'http', 'path' => '/']);

    expect(monitorsOf(Site::query()->sole()))->toBe(['site.http', 'site.path', 'site.redirect']);
});

test('default monitors do not reach the change feed', function () {
    Queue::fake();

    $this->actingAs($this->admin)->post(route('sites.store'), ['hostname' => 'svet24.si', 'scheme' => 'https', 'path' => '/']);

    expect(Activity::query()->where('type', 'like', 'monitor.%')->count())->toBe(0);
});

test('imports spread their first runs instead of running everything at once', function () {
    Queue::fake();
    app(DnsResolver::class)->answer('svet24.si', ['198.51.100.7']);

    $this->actingAs($this->admin)->postJson(route('sites.import.store'), ['url' => 'svet24.si'])->assertOk();

    $nextRuns = Monitor::query()->pluck('next_run_at');

    expect($nextRuns)->toHaveCount(6)
        ->and($nextRuns->every(fn ($at) => $at->between(now()->subSecond(), now()->addMinutes(10))))->toBeTrue();
    Queue::assertNothingPushed();
});

test('a new server gets TCP monitors for the ports that answer', function () {
    Queue::fake([RunMonitor::class]);
    app(TcpProbe::class)->open('192.0.2.10', 22)->open('192.0.2.10', 443);

    $this->actingAs($this->admin)->post(route('servers.store'), [
        'name' => 'web02',
        'addresses' => [['ip' => '192.0.2.10', 'is_primary' => true]],
    ])->assertSessionHasNoErrors();

    expect(monitorsOf(Server::query()->sole()))->toBe(['server.tcp SSH', 'server.tcp HTTPS']);
});

test('sync-defaults adds a default monitor to resources that lack it', function () {
    Queue::fake();
    $site = Site::factory()->create(['scheme' => 'http']);
    $covered = Site::factory()->create();
    Monitor::factory()->on($covered, 'site.http')->create();

    $this->artisan('monitors:sync-defaults', ['--checker' => 'site.http', '--dry-run' => true])
        ->expectsOutputToContain('Would add 1 monitors.');
    expect(monitorsOf($site))->toBe([]);

    $this->artisan('monitors:sync-defaults', ['--checker' => 'site.http'])->expectsOutputToContain('Added 1 monitors.');
    $this->artisan('monitors:sync-defaults', ['--checker' => 'site.ssl'])->expectsOutputToContain('Added 1 monitors.');

    expect(monitorsOf($site))->toBe(['site.http'])
        ->and(monitorsOf($covered))->toBe(['site.http', 'site.ssl']);
});

test('sync-defaults needs a registered checker', function (?string $checker) {
    $this->artisan('monitors:sync-defaults', array_filter(['--checker' => $checker]))->assertFailed();
})->with([null, 'site.nope']);

test('a serving path change and a restore make monitors due now', function () {
    $site = Site::factory()->create();
    $path = Monitor::factory()->on($site, 'site.path')->create(['next_run_at' => now()->addHour()]);
    $http = Monitor::factory()->on($site, 'site.http')->create(['next_run_at' => now()->addHour()]);

    $this->actingAs($this->admin)->put(route('sites.path', $site), ['front_service_id' => null, 'server_id' => Server::factory()->create()->id]);

    expect($path->fresh()->next_run_at->isFuture())->toBeFalse()
        ->and($http->fresh()->next_run_at->isFuture())->toBeTrue();

    $site->delete();
    $site->restore();

    expect($http->fresh()->next_run_at->isFuture())->toBeFalse();
});

test('the network canary needs two answering targets and is cached', function () {
    $probe = new FakeTcpProbe;
    app()->instance(TcpProbe::class, $probe->open('1.1.1.1', 443));
    expect(app(NetworkHealth::class)->isHealthy())->toBeFalse();

    Cache::forget('network-health');
    app(DnsResolver::class)->answer('one.one.one.one', ['1.1.1.1']);
    expect(app(NetworkHealth::class)->isHealthy())->toBeTrue();

    app()->instance(TcpProbe::class, new FakeTcpProbe);
    expect(app(NetworkHealth::class)->isHealthy())->toBeTrue();
});
