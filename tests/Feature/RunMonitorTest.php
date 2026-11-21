<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Jobs\RunMonitor;
use App\Models\Activity;
use App\Models\Monitor;
use App\Models\MonitorRun;
use App\Models\Site;
use App\Monitoring\MonitorClaimer;
use App\Net\Fake\FakeTcpProbe;
use App\Net\TcpProbe;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeChecker;

/*
 * The run pipeline end to end with a FakeChecker registered as `site.fake`
 * (SPEC §5.3, §17).
 */
beforeEach(function () {
    FakeChecker::register();
    $this->site = Site::factory()->create();
    $this->monitor = Monitor::factory()->on($this->site, 'site.fake')->create();
});

function runIt(): void
{
    RunMonitor::dispatchSync(test()->monitor->id);
}

it('stores the run, the state and the resource status', function () {
    FakeChecker::then(CheckResult::ok('Fine', ['version' => '1.0']));

    runIt();

    $state = $this->monitor->state()->first();
    $run = MonitorRun::query()->sole();

    expect($run->status)->toBe(Status::Ok)
        ->and($run->message)->toBeNull()
        ->and($run->data)->toBeNull()
        ->and($state->status)->toBe(Status::Ok)
        ->and($state->data)->toBe(['version' => '1.0'])
        ->and($state->consecutive_successes)->toBe(1)
        ->and($this->site->fresh()->status)->toBe(Status::Ok);
});

it('keeps the data of failing runs and confirms the failure a minute later', function () {
    $this->monitor->forceFill(['next_run_at' => now()->addMinutes(5)])->saveQuietly();
    FakeChecker::then(CheckResult::error('Down', ['code' => 500]));

    runIt();

    $run = MonitorRun::query()->sole();

    expect($run->data)->toBe(['code' => 500])
        ->and($run->message)->toBe('Down')
        ->and($this->monitor->state()->first()->isPending())->toBeTrue()
        ->and($this->monitor->fresh()->next_run_at->diffInSeconds(now()->addMinute(), true))->toBeLessThan(2);
});

it('turns CheckCouldNotRun into Unknown without reporting it', function () {
    Exceptions::fake();
    FakeChecker::then(fn () => throw new CheckCouldNotRun('Resolver timed out'));

    runIt();

    expect(MonitorRun::query()->sole()->message)->toBe('Resolver timed out')
        ->and($this->monitor->state()->first()->consecutive_unknowns)->toBe(1);
    Exceptions::assertNothingReported();
});

it('reports a crashing checker and records Unknown', function () {
    Exceptions::fake();
    FakeChecker::then(fn () => throw new LogicException('Oops'));

    runIt();

    expect(MonitorRun::query()->sole())->status->toBe(Status::Unknown)->message->toBe('Checker error: Oops');
    Exceptions::assertReported(LogicException::class);
});

it('discards failures while Sextant itself is offline', function () {
    app()->instance(TcpProbe::class, new FakeTcpProbe);
    FakeChecker::then(CheckResult::error('Down'), CheckResult::ok('Up'));

    runIt();
    expect(MonitorRun::query()->latest('id')->first())
        ->status->toBe(Status::Unknown)
        ->message->toBe("Discarded: Sextant's own network check failed");

    runIt();
    expect(MonitorRun::query()->latest('id')->first()->status)->toBe(Status::Ok);
});

it('caps the severity at max_severity', function () {
    FakeChecker::register(['max_severity' => 'warning']);
    FakeChecker::then(CheckResult::error('Bad'));

    runIt();

    expect(MonitorRun::query()->sole()->status)->toBe(Status::Warning);
});

it('runs an unregistered checker to Unknown, reported once a day', function () {
    Exceptions::fake();
    $monitor = Monitor::factory()->on($this->site, 'site.retired')->create();

    RunMonitor::dispatchSync($monitor->id);
    RunMonitor::dispatchSync($monitor->id);

    expect(MonitorRun::query()->where('monitor_id', $monitor->id)->pluck('message')->unique()->all())
        ->toBe(['Checker `site.retired` is not registered']);
    Exceptions::assertReportedCount(1);
});

it('skips disabled monitors and deleted resources', function (Closure $change) {
    $change($this);

    runIt();

    expect(MonitorRun::query()->count())->toBe(0)->and(FakeChecker::$runs)->toBe(0);
})->with([
    'disabled' => [fn ($test) => $test->monitor->forceFill(['enabled' => false])->saveQuietly()],
    'site deleted' => [fn ($test) => $test->site->delete()],
]);

it('records a detected change of a tracked field, but not on the first run', function () {
    FakeChecker::then(
        CheckResult::ok('v1', ['version' => '1.0']),
        CheckResult::ok('v1', ['version' => '1.0']),
        CheckResult::ok('v2', ['version' => '2.0']),
    );

    runIt();
    runIt();
    runIt();

    $activity = Activity::query()->where('category', 'detected')->sole();

    expect($activity->type)->toBe('software.upgraded')
        ->and($activity->summary)->toBe("{$this->site->display_hostname}: version changed from 1.0 to 2.0")
        ->and($activity->monitor_id)->toBe($this->monitor->id)
        ->and($activity->user_id)->toBeNull()
        ->and(MonitorRun::query()->whereNotNull('data')->count())->toBe(1);
});

it('claims each run once, however many dispatchers try', function () {
    Queue::fake();
    $this->monitor->forceFill(['next_run_at' => now()->subMinute()->startOfSecond()])->saveQuietly();
    $stale = $this->monitor->fresh();

    expect(app(MonitorClaimer::class)->claim($stale))->toBeTrue()
        ->and(app(MonitorClaimer::class)->claim($stale))->toBeFalse()
        ->and($this->monitor->fresh()->next_run_at->isFuture())->toBeTrue();
    Queue::assertPushedOn('checks', RunMonitor::class);
    Queue::assertPushed(RunMonitor::class, 1);
});

it('dispatches due monitors of live resources only', function () {
    Queue::fake();
    $due = now()->subMinute()->startOfSecond();
    $this->monitor->forceFill(['next_run_at' => $due])->saveQuietly();
    Monitor::factory()->on(Site::factory()->create(), 'site.fake')->create(['next_run_at' => now()->addMinute()]);
    Monitor::factory()->on(Site::factory()->create(), 'site.fake')->disabled()->create();
    Monitor::factory()->on(tap(Site::factory()->create())->delete(), 'site.fake')->create(['next_run_at' => $due]);

    $this->artisan('monitors:dispatch')->expectsOutputToContain('1 runs queued.')->assertSuccessful();

    Queue::assertPushed(RunMonitor::class, fn (RunMonitor $job) => $job->monitorId === $this->monitor->id);
    Queue::assertPushed(RunMonitor::class, 1);
});

it('runs one monitor from the command line', function () {
    FakeChecker::then(CheckResult::warning('Slow'));

    $this->artisan('monitors:run', ['monitor' => $this->monitor->id, '--no-save' => true])
        ->expectsOutputToContain('Slow')
        ->assertSuccessful();

    expect(MonitorRun::query()->count())->toBe(0);
});

it('marks stale monitors Unknown and refreshes resource statuses', function () {
    FakeChecker::then(CheckResult::ok('Fine'));
    runIt();

    $this->travel(16)->minutes();
    $this->artisan('sextant:sweep')->expectsOutputToContain('1 monitors went stale.')->assertSuccessful();

    expect($this->monitor->state()->first()->status)->toBe(Status::Unknown)
        ->and($this->site->fresh()->status)->toBe(Status::Unknown);
});

it('leaves disabled and muted monitors out of the resource status', function () {
    FakeChecker::then(CheckResult::ok('Fine'));
    runIt();
    $this->monitor->forceFill(['muted_until' => now()->addHour()])->saveQuietly();

    $this->artisan('sextant:sweep');

    expect($this->site->fresh()->status)->toBe(Status::Unknown);
});
