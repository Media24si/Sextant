<?php

use App\Checks\CheckResult;
use App\Enums\IncidentEventType;
use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Jobs\RunMonitor;
use App\Models\Activity;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorState;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Models\User;
use App\Monitoring\IncidentManager;
use App\Monitoring\MonitorRunner;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeChecker;

/*
 * Grouping by backend server (SPEC §6.5), reminders (§6.3, §6.6) and
 * resolution when monitors or resources go away (§6.4).
 */
beforeEach(function () {
    FakeChecker::register();
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    ServerAddress::factory()->for($this->web02)->primary()->create(['ip' => '192.0.2.10']);
    $this->tcp = Monitor::factory()->on($this->web02, 'server.tcp')->create(['label' => 'HTTPS', 'arguments' => ['port' => 443]]);
    $this->site = Site::factory()->create(['server_id' => $this->web02->id]);
    $this->http = Monitor::factory()->on($this->site, 'site.fake')->create();
    $this->manager = app(IncidentManager::class);
});

function openOn(Monitor $monitor, string $status = 'error'): Incident
{
    return app(IncidentManager::class)->open($monitor, $monitor->resource(), new CheckResult(Status::from($status), 'Down'), now());
}

it('puts a site incident under its backend server\'s outage', function () {
    $outage = openOn($this->tcp);

    expect(openOn($this->http)->parent_id)->toBe($outage->id);
});

it('does not group under a warning-level server incident', function () {
    openOn($this->tcp, 'warning');

    expect(openOn($this->http)->parent_id)->toBeNull();
});

it('adopts its sites\' recent incidents when a server outage opens', function () {
    $recent = openOn($this->http);
    $otherSite = Site::factory()->create(['server_id' => $this->web02->id]);
    $old = Incident::factory()->create([
        'monitor_id' => Monitor::factory()->on($otherSite, 'site.fake')->create()->id,
        'opened_at' => now()->subMinutes(20),
    ]);
    $elsewhere = openOn(Monitor::factory()->on(Site::factory()->create(), 'site.fake')->create());

    $outage = openOn($this->tcp);

    expect($recent->fresh()->parent_id)->toBe($outage->id)
        ->and($old->fresh()->parent_id)->toBeNull()
        ->and($elsewhere->fresh()->parent_id)->toBeNull();
});

it('uses the earliest server.tcp outage as the parent', function () {
    $first = openOn($this->tcp);
    $this->travel(1)->minutes();
    openOn(Monitor::factory()->on($this->web02, 'server.tcp')->create(['label' => 'SSH', 'arguments' => ['port' => 22]]));

    expect(openOn($this->http)->parent_id)->toBe($first->id);
});

it('checks the backend server at a site\'s first failure', function () {
    Queue::fake();
    FakeChecker::then(CheckResult::error('Down'));

    (new RunMonitor($this->http->id))->handle(app(MonitorRunner::class));

    Queue::assertPushed(RunMonitor::class, fn (RunMonitor $job) => $job->monitorId === $this->tcp->id);
});

it('skips the dependency check when the server ran a moment ago', function () {
    Queue::fake();
    MonitorState::query()->whereKey($this->tcp->id)->update(['checked_at' => now()->subSeconds(20)]);
    FakeChecker::then(CheckResult::error('Down'));

    (new RunMonitor($this->http->id))->handle(app(MonitorRunner::class));

    Queue::assertNotPushed(RunMonitor::class);
});

it('reminds about a child still failing ten minutes after its parent recovered, once', function () {
    $outage = openOn($this->tcp);
    $child = openOn($this->http);
    $this->manager->resolve($outage, IncidentResolution::Recovered, now());

    $this->travel(5)->minutes();
    $this->artisan('sextant:sweep');
    expect($child->events()->where('type', IncidentEventType::Reminder)->count())->toBe(0);

    $this->travel(6)->minutes();
    $this->artisan('sextant:sweep');
    $this->artisan('sextant:sweep');

    $reminder = $child->events()->where('type', IncidentEventType::Reminder)->sole();

    expect($reminder)->message->toBe('Still failing after web02 recovered')->notify->toBeTrue()
        ->and($child->fresh()->parent_id)->toBe($outage->id);
});

it('reminds about unacknowledged error incidents every four hours', function () {
    $incident = openOn($this->http);
    $warning = openOn(Monitor::factory()->on($this->site, 'site.fake')->create(['label' => 'B']), 'warning');
    $acknowledged = openOn(Monitor::factory()->on($this->site, 'site.fake')->create(['label' => 'C']));
    $this->manager->acknowledge($acknowledged, User::factory()->admin()->create());

    $this->travel(4)->hours();
    $this->artisan('sextant:sweep')->expectsOutputToContain('1 reminders.');
    $this->artisan('sextant:sweep')->expectsOutputToContain('0 reminders.');

    expect($incident->events()->where('type', IncidentEventType::Reminder)->sole()->message)->toBe('Still failing after 4 h')
        ->and($incident->fresh()->last_notified_at)->not->toBeNull()
        ->and($warning->events()->where('type', IncidentEventType::Reminder)->exists())->toBeFalse();
});

it('reminds when a mute ends during an incident', function () {
    $this->artisan('sextant:sweep');
    $this->http->update(['muted_until' => now()->addMinutes(30)]);
    $incident = openOn($this->http);

    $this->travel(31)->minutes();
    $this->artisan('sextant:sweep');

    expect($incident->events()->where('type', IncidentEventType::Reminder)->sole()->message)->toBe('Still failing after the mute ended');
});

it('resolves incidents when the monitor is disabled or deleted, or its resource is deleted', function () {
    $disabled = openOn($this->http);
    $this->http->update(['enabled' => false]);

    $other = Monitor::factory()->on($this->site, 'site.fake')->create(['label' => 'B']);
    $deleted = openOn($other);
    $other->delete();

    $serverIncident = openOn($this->tcp);
    $this->web02->delete();

    expect($disabled->fresh()->resolution)->toBe(IncidentResolution::MonitorDisabled)
        ->and($deleted->fresh()->resolution)->toBe(IncidentResolution::MonitorDeleted)
        ->and($serverIncident->fresh()->resolution)->toBe(IncidentResolution::ResourceDeleted);
});

it('forgets a disabled monitor\'s state, so re-enabling it starts afresh', function () {
    openOn($this->http);
    MonitorState::query()->whereKey($this->http->id)->update(['status' => Status::Error, 'consecutive_failures' => 3]);

    $this->http->update(['enabled' => false]);

    $state = MonitorState::query()->find($this->http->id);

    expect($state->status)->toBe(Status::Unknown)
        ->and($state->consecutive_failures)->toBe(0);
});

it('mutes a server together with the sites it serves', function () {
    $admin = User::factory()->admin()->create();
    $elsewhere = Monitor::factory()->on(Site::factory()->create(), 'site.fake')->create();

    $this->actingAs($admin)->post(route('servers.mute', $this->web02), ['until' => now()->addHours(2)->toIso8601String()])
        ->assertSessionHasNoErrors();

    expect($this->tcp->fresh()->isMuted())->toBeTrue()
        ->and($this->http->fresh()->isMuted())->toBeTrue()
        ->and($elsewhere->fresh()->isMuted())->toBeFalse()
        ->and(Activity::query()->where('type', 'monitor.muted')->sole()->summary)->toStartWith('web02 and 1 site: all monitors muted until');

    $this->post(route('servers.mute', $this->web02), ['until' => null]);

    expect($this->http->fresh()->isMuted())->toBeFalse();
});

it('refuses a mute in the past or longer than 30 days', function (string $until) {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('monitors.mute', $this->http), ['until' => $until])
        ->assertSessionHasErrors('until');
})->with([
    'past' => fn () => now()->subHour()->toIso8601String(),
    'too long' => fn () => now()->addDays(31)->toIso8601String(),
]);

it('leaves muted monitors out of the resource status', function () {
    openOn($this->http);
    MonitorState::query()->whereKey($this->http->id)->update(['status' => Status::Error]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('monitors.mute', $this->http), ['until' => now()->addHour()->toIso8601String()]);

    expect($this->site->fresh()->status)->toBe(Status::Unknown);
});
