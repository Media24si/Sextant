<?php

use App\Checks\Arguments;
use App\Checks\CheckResult;
use App\Checks\ResolvedSettings;
use App\Enums\Status;
use App\Models\MonitorState;
use App\Monitoring\StateMachine;
use Carbon\CarbonImmutable;

/*
 * SPEC §6.1, §6.2. Without incidents (Phase 3), failures leave the monitor
 * OK and Pending.
 */
function settings(int $failures = 2, int $interval = 5): ResolvedSettings
{
    return new ResolvedSettings(new Arguments([], []), $interval, $failures, 1, Status::Error, true, 'checks');
}

function blankState(): MonitorState
{
    return new MonitorState([
        'status' => Status::Unknown,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
        'consecutive_unknowns' => 0,
    ]);
}

/**
 * @param  list<string>  $statuses
 */
function runSequence(MonitorState $state, array $statuses, ?CarbonImmutable $now = null): array
{
    $now ??= CarbonImmutable::parse('2026-11-21 10:00:00');
    $transitions = [];

    foreach ($statuses as $index => $status) {
        $result = new CheckResult(Status::from($status), "run {$index}", ['n' => $index]);
        $transitions[] = app(StateMachine::class)->apply($state, $result, settings(), 100, $now->addMinutes($index));
    }

    return $transitions;
}

it('counts streaks and keeps unknowns out of them', function (array $sequence, int $failures, int $successes, int $unknowns) {
    $state = blankState();
    runSequence($state, $sequence);

    expect([$state->consecutive_failures, $state->consecutive_successes, $state->consecutive_unknowns])
        ->toBe([$failures, $successes, $unknowns]);
})->with([
    'fail, unknown, fail is two failures' => [['error', 'unknown', 'error'], 2, 0, 0],
    'a success breaks the streak' => [['error', 'error', 'ok'], 0, 1, 0],
    'warning and error both count' => [['warning', 'error', 'warning'], 3, 0, 0],
    'unknowns pile up after a failure' => [['error', 'unknown', 'unknown'], 1, 0, 2],
]);

it('is unknown until the first definitive result, then OK even while failing', function () {
    $state = blankState();

    runSequence($state, ['unknown']);
    expect($state->status)->toBe(Status::Unknown)->and($state->last_definitive_at)->toBeNull();

    runSequence($state, ['error']);
    expect($state->status)->toBe(Status::Ok)
        ->and($state->last_result_status)->toBe(Status::Error)
        ->and($state->isPending())->toBeTrue();
});

it('keeps the data of the last definitive result', function () {
    $state = blankState();
    runSequence($state, ['ok', 'unknown']);

    expect($state->data)->toBe(['n' => 0])->and($state->message)->toBe('run 1');
});

it('goes stale after interval × 2 + 5 minutes', function () {
    $state = blankState();
    [$transition] = runSequence($state, ['ok']);

    expect($transition->statusChanged())->toBeTrue()
        ->and($state->stale_at->toDateTimeString())->toBe('2026-11-21 10:15:00');
});

it('asks for a confirmation run until the failure reaches the threshold', function () {
    $transitions = runSequence(blankState(), ['error', 'error', 'error']);

    expect(array_map(fn ($transition) => $transition->recheckSoon, $transitions))->toBe([true, false, false]);
});
