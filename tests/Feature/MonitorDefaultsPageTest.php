<?php

use App\Checks\CheckerRegistry;
use App\Models\CheckerSetting;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Monitor defaults: per-checker values every monitor inherits, between
 * `config/checkers.php` and the monitor's own overrides.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists every checker with the config values and the stored defaults', function () {
    CheckerSetting::factory()->create(['checker' => 'site.ssl', 'arguments' => ['warning_days' => 10], 'interval_minutes' => 60]);

    $this->actingAs($this->admin)->get(route('checker-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('checker-settings/Index')
            ->has('checkers', count(app(CheckerRegistry::class)->configEntries()))
            ->where('checkers', fn ($checkers) => collect($checkers)->firstWhere('key', 'site.ssl')['overrides'] === ['warning_days' => 10]
                && collect($checkers)->firstWhere('key', 'site.ssl')['interval_minutes'] === 60
                && collect($checkers)->firstWhere('key', 'site.ssl')['interval'] === 720
                && collect(collect($checkers)->firstWhere('key', 'site.ssl')['arguments'])->firstWhere('name', 'warning_days')['default'] === 14
                && collect(collect($checkers)->firstWhere('key', 'server.tcp')['arguments'])->pluck('name')->doesntContain('address')));
});

it('saves defaults that monitors without their own value then use', function () {
    $monitor = Monitor::factory()->on(Site::factory()->create(), 'site.ssl')->create();

    $this->actingAs($this->admin)
        ->put(route('checker-settings.update', 'site.ssl'), [
            'arguments' => ['warning_days' => '10', 'error_days' => '3', 'port' => ''],
            'interval_minutes' => '',
            'failures_before_incident' => '3',
            'successes_before_resolve' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $setting = CheckerSetting::query()->where('checker', 'site.ssl')->sole();
    $settings = app(CheckerRegistry::class)->settings($monitor);

    expect($setting->arguments)->toEqual(['warning_days' => 10, 'error_days' => 3])
        ->and($setting->interval_minutes)->toBeNull()
        ->and($settings->arguments->int('warning_days'))->toBe(10)
        ->and($settings->arguments->int('error_days'))->toBe(3)
        ->and($settings->failuresBeforeIncident)->toBe(3)
        ->and($settings->interval)->toBe(720);
});

it('resets to the config when every field is empty', function () {
    CheckerSetting::factory()->create(['checker' => 'site.ssl']);

    $this->actingAs($this->admin)
        ->put(route('checker-settings.update', 'site.ssl'), ['arguments' => ['warning_days' => ''], 'interval_minutes' => ''])
        ->assertRedirect();

    expect(CheckerSetting::query()->count())->toBe(0)
        ->and(app(CheckerRegistry::class)->entry('site.ssl')['arguments']['warning_days'])->toBe(14);
});

it('validates the values with the checker schema', function () {
    $this->actingAs($this->admin)
        ->put(route('checker-settings.update', 'site.ssl'), [
            'arguments' => ['warning_days' => '400', 'nope' => '1'],
            'failures_before_incident' => '0',
        ])
        ->assertSessionHasErrors(['arguments.warning_days', 'arguments.nope', 'failures_before_incident']);

    $this->actingAs($this->admin)
        ->put(route('checker-settings.update', 'server.tcp'), ['arguments' => ['address' => '10.0.0.1']])
        ->assertSessionHasErrors('arguments.address');

    expect(CheckerSetting::query()->count())->toBe(0);
});

it('is not found for an unknown checker', function () {
    $this->actingAs($this->admin)
        ->put(route('checker-settings.update', 'site.nope'), ['interval_minutes' => '5'])
        ->assertNotFound();
});
