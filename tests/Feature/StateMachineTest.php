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
use App\Models\Site;
use App\Monitoring\IncidentManager;
use App\Monitoring\IncidentPresenter;
use App\Monitoring\MonitorClaimer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Fakes\FakeChecker;

/*
 * The state machine (SPEC §6.1, §6.2) end to end through the runner, with a
 * FakeChecker as `site.fake`: 2 failures open an incident, 1 pass resolves it.
 */
beforeEach(function () {
    FakeChecker::register();
    $this->site = Site::factory()->create();
    $this->monitor = Monitor::factory()->on($this->site, 'site.fake')->create();
});

/**
 * Run the monitor once per status, a minute apart.
 */
function runSequence(string ...$statuses): void
{
    foreach ($statuses as $index => $status) {
        FakeChecker::then(new CheckResult(Status::from($status), "run {$index}", ['n' => $index]));
        RunMonitor::dispatchSync(test()->monitor->id);
        test()->travel(1)->minutes();
    }
}

function state(): MonitorState
{
    return test()->monitor->state()->firstOrFail();
}

function openIncident(): ?Incident
{
    return Incident::query()->open()->where('monitor_id', test()->monitor->id)->first();
}

it('counts streaks and keeps unknowns out of them', function (array $sequence, int $failures, int $successes, int $unknowns) {
    runSequence(...$sequence);

    expect([state()->consecutive_failures, state()->consecutive_successes, state()->consecutive_unknowns])
        ->toBe([$failures, $successes, $unknowns]);
})->with([
    'fail, unknown, fail is two failures' => [['error', 'unknown', 'error'], 2, 0, 0],
    'a success breaks the streak' => [['error', 'error', 'ok'], 0, 1, 0],
    'warning and error both count' => [['warning', 'error', 'warning'], 3, 0, 0],
    'unknowns pile up after a failure' => [['error', 'unknown', 'unknown'], 1, 0, 2],
]);

it('is unknown until the first definitive result, then pending on a first failure', function () {
    runSequence('unknown');
    expect(state()->status)->toBe(Status::Unknown);

    runSequence('error');
    expect(state())->status->toBe(Status::Ok)->last_result_status->toBe(Status::Error)
        ->and(state()->isPending())->toBeTrue()
        ->and(openIncident())->toBeNull();
});

it('opens an incident at the threshold, and the status follows its severity', function () {
    runSequence('error', 'error');

    $incident = openIncident();

    expect($incident)->severity->toBe(Status::Error)->occurrences->toBe(1)->title->toBe("{$this->site->display_hostname} — Fake")
        ->and($incident->server_id)->toBe($this->site->server_id)
        ->and(state()->status)->toBe(Status::Error)
        ->and(state()->isPending())->toBeFalse()
        ->and($this->site->fresh()->status)->toBe(Status::Error)
        ->and($incident->events()->pluck('type')->all())->toBe([IncidentEventType::Opened])
        ->and(Activity::query()->where('type', 'incident.opened')->sole()->incident_id)->toBe($incident->id);
});

it('opens across an unknown in the streak', function () {
    runSequence('error', 'unknown', 'error');

    expect(openIncident())->not->toBeNull();
});

it('escalates and de-escalates, keeping the worst severity', function () {
    runSequence('warning', 'warning', 'error', 'warning');

    $incident = openIncident();

    expect($incident)->severity->toBe(Status::Warning)->max_severity->toBe(Status::Error)->occurrences->toBe(3)
        ->and($incident->events()->orderBy('id')->get()->map(fn ($event) => [$event->type->value, $event->notify])->all())
        ->toBe([['opened', true], ['escalated', true], ['deescalated', false]]);
});

it('resolves after enough passes and marks the monitor recovering until then', function () {
    FakeChecker::register(['successes_before_resolve' => 2]);
    runSequence('error', 'error', 'ok');

    $incident = openIncident();
    expect($incident)->not->toBeNull()
        ->and(state()->status)->toBe(Status::Error)
        ->and(app(IncidentPresenter::class)->row($incident->load(IncidentPresenter::WITH))->recovering)->toBeTrue();

    runSequence('ok');

    expect($incident->fresh())->resolution->toBe(IncidentResolution::Recovered)->resolved_at->not->toBeNull()
        ->and(state()->status)->toBe(Status::Ok)
        ->and(Activity::query()->where('type', 'incident.resolved')->exists())->toBeTrue();
});

it('a pass before the threshold resets the streak without an incident', function () {
    runSequence('error', 'ok', 'error');

    expect(openIncident())->toBeNull()->and(state()->consecutive_failures)->toBe(1);
});

it('opens at most at max_severity', function () {
    FakeChecker::register(['max_severity' => 'warning']);
    runSequence('error', 'error');

    expect(openIncident()->severity)->toBe(Status::Warning);
});

it('confirms a pending failure and a recovery a minute later', function () {
    FakeChecker::register(['successes_before_resolve' => 2]);
    $this->monitor->forceFill(['next_run_at' => now()->addHour()])->saveQuietly();

    runSequence('error');
    expect($this->monitor->fresh()->next_run_at->lte(now()))->toBeTrue();

    runSequence('error');
    $this->monitor->forceFill(['next_run_at' => now()->addHour()])->saveQuietly();
    runSequence('ok');
    expect($this->monitor->fresh()->next_run_at->lte(now()))->toBeTrue();
});

it('keeps an open incident\'s severity when the state goes stale', function () {
    runSequence('error', 'error');
    $this->travel(30)->minutes();

    $this->artisan('sextant:sweep')->assertSuccessful();

    expect(state()->status)->toBe(Status::Error);
});

it('never has two open incidents for one monitor', function () {
    $manager = app(IncidentManager::class);
    $result = CheckResult::error('Down');

    $first = $manager->open($this->monitor, $this->site, $result, now());
    $second = $manager->open($this->monitor, $this->site, $result, now());

    expect($second->id)->toBe($first->id)
        ->and(Incident::query()->where('monitor_id', $this->monitor->id)->count())->toBe(1)
        ->and($first->events()->count())->toBe(1);

    expect(fn () => Incident::factory()->create(['monitor_id' => $this->monitor->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reaches an open incident within interval × threshold + 1 minute, dispatching on whole minutes', function () {
    // GOAL success criterion: HTTP every minute, two failures. The outage starts
    // just after a passing run at 10:00:05; dispatch runs at the top of each minute.
    FakeChecker::register(['interval' => 1]);
    Monitor::query()->whereKey($this->monitor->id)->update(['next_run_at' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:05'));
    $claimer = app(MonitorClaimer::class);
    $this->monitor->forceFill(['next_run_at' => now()->startOfMinute()])->saveQuietly();
    $outageStart = now();

    $openedAt = null;

    foreach (range(1, 5) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00')->addMinutes($minute));
        FakeChecker::then(CheckResult::error('Down'));
        $claimer->dispatchDue(now()->addSeconds(50));

        if (openIncident() !== null) {
            $openedAt = openIncident()->opened_at;
            break;
        }
    }

    // Settle 20 s and the 30 s flush bring the Discord message under 3 minutes.
    expect($openedAt)->not->toBeNull()
        ->and($outageStart->diffInSeconds($openedAt->copy()->addSeconds(20 + 30)))->toBeLessThanOrEqual(180);
});
