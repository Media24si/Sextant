<?php

use App\Models\User;
use Carbon\CarbonImmutable;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

use App\Checks\CheckResult;
use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorState;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\IncidentManager;
use App\Monitoring\MonitorClaimer;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Dashboard props (SPEC §13.7): the contract, worst-first then oldest-first
 * grouping, children under their server, muted apart, polling keys.
 */
function openIncidentOn(Server|Domain|Site $resource, string $checker, string $status, ?string $label = null): Incident
{
    $monitor = Monitor::factory()->on($resource, $checker)->create(['label' => $label]);

    return app(IncidentManager::class)->open($monitor, $resource, new CheckResult(Status::from($status), "{$checker} failed"), now());
}

test('the dashboard props follow the contract', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('totals', fn (Assert $totals) => $totals
                ->has('servers', fn (Assert $counts) => $counts->hasAll(['total', 'ok', 'warning', 'error', 'unknown']))
                ->hasAll(['domains', 'sites', 'sites_unassigned', 'servers_below_minimum', 'servers_collector_outdated']))
            ->hasAll(['issues', 'muted', 'pending_count', 'resolved', 'software', 'expiring', 'changes'])
            ->has('system', fn (Assert $system) => $system->hasAll(['last_dispatch_at', 'checks_running', 'stale_monitors', 'network_healthy'])));
});

test('totals count resources by status', function () {
    Server::factory()->count(2)->create();
    Server::factory()->create()->forceFill(['status' => Status::Error])->saveQuietly();
    Site::factory()->create(['server_id' => null]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.servers', ['total' => 3, 'ok' => 0, 'warning' => 0, 'error' => 1, 'unknown' => 2])
            ->where('totals.sites.total', 1)
            ->where('totals.sites_unassigned', 1));
});

test('issues are grouped by resource, worst first then oldest first, with sites under their server', function () {
    $web02 = Server::factory()->create(['name' => 'web02']);
    $domain = Domain::factory()->create(['name' => 'oglasi24.si']);

    $warning = openIncidentOn($domain, 'domain.expiration', 'warning');
    $this->travel(1)->minutes();
    $outage = openIncidentOn($web02, 'server.tcp', 'error', 'HTTPS');
    $this->travel(1)->minutes();
    $site = Site::factory()->create(['server_id' => $web02->id, 'hostname' => 'vestnik.oglasi24.si', 'domain_id' => $domain->id]);
    $child = openIncidentOn($site, 'site.http', 'error');
    $this->travel(1)->minutes();
    $later = openIncidentOn(Server::factory()->create(['name' => 'web03']), 'server.tcp', 'error', 'SSH');

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('issues', 3)
            ->where('issues.0.resource.name', 'web02')
            ->where('issues.0.incidents.0.id', $outage->id)
            ->where('issues.0.children.0.id', $child->id)
            ->where('issues.0.children.0.serving_path', 'web02')
            ->where('issues.1.incidents.0.id', $later->id)
            ->where('issues.2.incidents.0.id', $warning->id)
            ->where('issues.2.resource.url', route('domains.show', $domain)));
});

test('muted incidents are listed apart and pending checks are counted', function () {
    $site = Site::factory()->create();
    $muted = openIncidentOn($site, 'site.http', 'error');
    $muted->monitor->update(['muted_until' => now()->addHour()]);
    $pending = Monitor::factory()->on($site, 'site.path')->create();
    MonitorState::query()->whereKey($pending->id)->update(['last_result_status' => Status::Warning]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('issues', 0)
            ->where('muted.0.incidents.0.id', $muted->id)
            ->where('pending_count', 1));
});

test('recently resolved lists root incidents of the last day', function () {
    $manager = app(IncidentManager::class);
    $old = openIncidentOn(Site::factory()->create(), 'site.http', 'error');
    $manager->resolve($old, IncidentResolution::Recovered, now());
    $this->travel(25)->hours();
    $recent = openIncidentOn(Site::factory()->create(), 'site.http', 'error');
    $manager->resolve($recent, IncidentResolution::Recovered, now());

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('resolved', 1)->where('resolved.0.id', $recent->id));
});

test('expiring lists domains and certificates within 60 days, soonest first', function () {
    Domain::factory()->create(['name' => 'svet24.si'])->forceFill(['expires_at' => now()->addDays(41)->toDateString()])->saveQuietly();
    Domain::factory()->create()->forceFill(['expires_at' => now()->addDays(200)->toDateString()])->saveQuietly();
    $site = Site::factory()->create();
    $ssl = Monitor::factory()->on($site, 'site.ssl')->create();
    MonitorState::query()->whereKey($ssl->id)->update(['data' => json_encode(['expires_at' => now()->addDays(19)->toIso8601String()])]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('expiring', 2)
            ->where('expiring.0.kind', 'certificate')
            ->where('expiring.0.days', 19)
            ->where('expiring.1.name', 'svet24.si')
            ->where('expiring.1.days', 41));
});

test('system health comes from the last dispatch and stale monitors', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:02:59'));
    Cache::forever(MonitorClaimer::LAST_DISPATCH_KEY, '2026-11-21T10:00:00+00:00');
    $monitor = Monitor::factory()->create();
    MonitorState::query()->whereKey($monitor->id)->update(['stale_at' => now()->subMinute()]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('system.last_dispatch_at', '2026-11-21T10:00:00+00:00')
            ->where('system.checks_running', true)
            ->where('system.stale_monitors', 1)
            ->where('system.network_healthy', null));
});

test('checks count as stopped three minutes after the last dispatch, by the server\'s clock', function () {
    Cache::forever(MonitorClaimer::LAST_DISPATCH_KEY, '2026-11-21T10:00:00+00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:03:01'));

    $this->actingAs(User::factory()->create())->get(route('servers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('system.checks_running', false)
            ->reloadOnly(['system'], fn (Assert $reload) => $reload->has('system')->missing('rows')));
});

test('a poll reloads only the props it asks for', function () {
    Activity::query()->delete();

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['issues', 'system'], fn (Assert $reload) => $reload->has('issues')->has('system')->missing('changes')));
});
