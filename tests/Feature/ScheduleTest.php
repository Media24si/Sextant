<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('horizon metrics are snapshotted every five minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'horizon:snapshot'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});

test('monitoring tasks are scheduled', function (string $command, string $expression) {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

    expect($event?->expression)->toBe($expression);
})->with([
    'dispatch every minute' => ['monitors:dispatch', '* * * * *'],
    'sweep every minute' => ['sextant:sweep', '* * * * *'],
    'prune runs nightly' => ['model:prune', '30 3 * * *'],
]);

test('notifications flush every thirty seconds and heartbeats every five minutes', function () {
    $events = collect(app(Schedule::class)->events());
    $flush = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'notifications:flush'));
    $heartbeat = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'sextant:heartbeat'));

    expect($flush?->repeatSeconds)->toBe(30)
        ->and($heartbeat?->expression)->toBe('*/5 * * * *');
});
