<?php

use App\Checks\CheckerRegistry;
use App\Checks\Server\TcpChecker;
use App\Models\Monitor;
use App\Models\Site;

/* SPEC §4.3, §4.5, §17: registry validation and settings resolution. */
it('ships a valid registry', function () {
    expect(app(CheckerRegistry::class)->validate())->toBe([]);

    $this->artisan('sextant:checkers')->expectsOutputToContain('The registry is valid.')->assertSuccessful();
});

it('reports a broken registry', function () {
    config([
        'checkers.checkers' => [
            ...config('checkers.checkers'),
            'site.broken' => ['class' => TcpChecker::class, 'queue' => 'fast', 'interval' => 0, 'failures_before_incident' => 1, 'successes_before_resolve' => 1, 'arguments' => ['port' => 1]],
            'domain.missing' => ['class' => 'App\\Checks\\Nope', 'queue' => 'checks', 'interval' => 1, 'failures_before_incident' => 1, 'successes_before_resolve' => 1, 'arguments' => []],
        ],
        'checkers.defaults.site' => [['checker' => 'site.gone']],
    ]);

    expect(app(CheckerRegistry::class)->validate())->toContain(
        'site.broken: App\Checks\Server\TcpChecker must extend SiteChecker',
        'site.broken: queue must be checks or checks-slow',
        'site.broken: interval must be a positive integer',
        'site.broken: no default for argument `address`',
        'domain.missing: class App\Checks\Nope does not exist',
        'defaults.site.0: checker site.gone is not registered',
    );

    $this->artisan('sextant:checkers')->assertFailed();
});

it('resolves a monitor over the registry entry', function () {
    $monitor = Monitor::factory()->on(Site::factory()->create(), 'site.http')->create([
        'arguments' => ['path' => '/novice'],
        'interval_minutes' => 5,
        'failures_before_incident' => 4,
    ]);

    $settings = app(CheckerRegistry::class)->settings($monitor);

    expect($settings->arguments->string('path'))->toBe('/novice')
        ->and($settings->arguments->int('timeout'))->toBe(10)
        ->and($settings->interval)->toBe(5)
        ->and($settings->failuresBeforeIncident)->toBe(4)
        ->and($settings->successesBeforeResolve)->toBe(1)
        ->and($settings->queue)->toBe('checks');
});

it('gives the schema with registry defaults for the form', function () {
    $schema = collect(app(CheckerRegistry::class)->schema('site.http'))->keyBy('name');

    expect($schema['expected_status'])->toMatchArray(['type' => 'intList', 'default' => [200]])
        ->and($schema['contains'])->toMatchArray(['nullable' => true, 'default' => null]);
});
