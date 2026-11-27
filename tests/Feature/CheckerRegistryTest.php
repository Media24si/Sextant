<?php

use App\Checks\CheckerRegistry;
use App\Checks\Server\TcpChecker;
use App\Models\CheckerSetting;
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

it('puts stored checker defaults between the config and the monitor', function () {
    CheckerSetting::factory()->create([
        'checker' => 'site.ssl',
        'arguments' => ['warning_days' => 10, 'error_days' => 3, 'gone' => 1],
        'interval_minutes' => 60,
    ]);
    $site = Site::factory()->create();
    $registry = app(CheckerRegistry::class);

    $inherits = $registry->settings(Monitor::factory()->on($site, 'site.ssl')->make());
    $overrides = $registry->settings(Monitor::factory()->on($site, 'site.ssl')->make(['arguments' => ['warning_days' => 20]]));

    expect($inherits->arguments->int('warning_days'))->toBe(10)
        ->and($inherits->arguments->int('error_days'))->toBe(3)
        ->and($inherits->arguments->int('port'))->toBe(443)
        ->and($inherits->interval)->toBe(60)
        ->and($inherits->failuresBeforeIncident)->toBe(2)
        ->and($overrides->arguments->int('warning_days'))->toBe(20)
        ->and($registry->entry('site.ssl')['arguments'])->not->toHaveKey('gone')
        ->and(collect($registry->schema('site.ssl'))->firstWhere('name', 'warning_days')['default'])->toBe(10)
        ->and($registry->validate())->toBe([]);
});

it('goes back to the config when the stored defaults are deleted', function () {
    $setting = CheckerSetting::factory()->create(['checker' => 'site.ssl', 'arguments' => ['warning_days' => 10]]);
    $registry = app(CheckerRegistry::class);

    expect($registry->entry('site.ssl')['arguments']['warning_days'])->toBe(10);

    $setting->delete();

    expect($registry->entry('site.ssl')['arguments']['warning_days'])->toBe(14);
});

it('ignores a stored default for an argument that depends on the resource', function () {
    CheckerSetting::factory()->create(['checker' => 'server.tcp', 'arguments' => ['address' => '10.0.0.1', 'timeout' => 9]]);

    expect(app(CheckerRegistry::class)->entry('server.tcp')['arguments'])->toMatchArray(['address' => null, 'timeout' => 9]);
});
