<?php

use App\Enums\Status;
use App\Jobs\RunMonitor;
use App\Models\Activity;
use App\Models\Monitor;
use App\Models\MonitorRun;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Models\Site;
use App\Models\User;
use App\Monitoring\ResourceStatus;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/* The Monitors UI (SPEC §13.6) and the effects of monitor changes (§6.4). */
beforeEach(function () {
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->reader = User::factory()->reader()->create();
    $this->site = Site::factory()->create();
    $this->monitor = Monitor::factory()->on($this->site, 'site.http')->create(['next_run_at' => now()->addHour()]);
});

test('resource pages show their monitors; only admins get the checkers to add', function () {
    $this->actingAs($this->reader)->get(route('sites.show', $this->site))
        ->assertInertia(fn (Assert $page) => $page
            ->has('monitors', 1)
            ->where('monitors.0.name', 'HTTP')
            ->where('monitors.0.status', 'unknown')
            ->where('checker_options', []));

    $this->actingAs($this->admin)->get(route('sites.show', $this->site))
        ->assertInertia(fn (Assert $page) => $page
            ->where('checker_options.0.key', 'site.http')
            ->has('checker_options.0.arguments', 6));
});

test('a TCP monitor is named after its label and port', function () {
    $server = Server::factory()->create();
    ServerAddress::factory()->for($server)->primary()->create();
    Monitor::factory()->on($server, 'server.tcp')->create(['label' => 'SSH', 'arguments' => ['port' => 22]]);

    $this->actingAs($this->reader)->get(route('servers.show', $server))
        ->assertInertia(fn (Assert $page) => $page->where('monitors.0.name', 'TCP port · SSH (22)'));
});

test('the monitors list filters pending, disabled and by checker', function () {
    $this->monitor->state->forceFill(['last_result_status' => Status::Error, 'status' => Status::Ok])->save();
    Monitor::factory()->on($this->site, 'site.path')->disabled()->create();

    $this->actingAs($this->reader)->get(route('monitors.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitors/Index')
            ->where('counts', ['all' => 2, 'pending' => 1, 'unknown' => 0, 'disabled' => 1, 'muted' => 0])
            ->has('rows.data', 2));

    $this->get(route('monitors.index', ['filter' => ['show' => 'pending']]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.pending', true));

    $this->get(route('monitors.index', ['filter' => ['checker' => 'site.path']]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.enabled', false));
});

test('the drawer shows effective settings and the last runs', function () {
    $this->monitor->update(['arguments' => ['path' => '/novice']]);
    MonitorRun::query()->create(['monitor_id' => $this->monitor->id, 'status' => Status::Error, 'duration_ms' => 40, 'message' => '500', 'checked_at' => now()]);

    $this->actingAs($this->reader)->getJson(route('monitors.show', $this->monitor))
        ->assertOk()
        ->assertJsonPath('settings.0', ['label' => 'Path', 'value' => '/novice', 'inherited' => false])
        ->assertJsonPath('settings.1', ['label' => 'Expected status codes', 'value' => '200', 'inherited' => true])
        ->assertJsonPath('runs.0.message', '500')
        ->assertJsonPath('form.arguments', ['path' => '/novice']);
});

test('an admin adds a monitor with overrides and it runs at once', function () {
    $this->actingAs($this->admin)->post(route('monitors.store'), [
        'monitorable_type' => 'site',
        'monitorable_id' => $this->site->id,
        'checker' => 'site.http',
        'label' => 'Shop',
        'arguments' => ['path' => '/shop', 'expected_status' => ['200', '301'], 'contains' => '', 'verify_tls' => false],
        'interval_minutes' => '5',
        'failures_before_incident' => '',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    $monitor = Monitor::query()->where('label', 'Shop')->sole();

    expect($monitor->arguments)->toEqual(['path' => '/shop', 'expected_status' => [200, 301], 'verify_tls' => false])
        ->and($monitor->interval_minutes)->toBe(5)
        ->and($monitor->failures_before_incident)->toBeNull()
        ->and(Activity::query()->where('type', 'monitor.created')->where('monitor_id', $monitor->id)->sole()->summary)->toBe("{$this->site->display_hostname}: Shop monitor added");
    Queue::assertPushed(RunMonitor::class, fn (RunMonitor $job) => $job->monitorId === $monitor->id);
});

test('monitor input is validated against the checker schema', function (array $input, string $error) {
    $this->actingAs($this->admin)->post(route('monitors.store'), [
        'monitorable_type' => 'site',
        'monitorable_id' => $this->site->id,
        'checker' => 'site.http',
        'label' => 'Other',
        ...$input,
    ])->assertSessionHasErrors($error);
})->with([
    'checker for another type' => [['checker' => 'server.tcp'], 'checker'],
    'unknown checker' => [['checker' => 'site.nope'], 'checker'],
    'path without slash' => [['arguments' => ['path' => 'novice']], 'arguments.path'],
    'timeout too long' => [['arguments' => ['timeout' => 90]], 'arguments.timeout'],
    'not an argument' => [['arguments' => ['retries' => 3]], 'arguments.retries'],
    'same check twice' => [['label' => ''], 'label'],
    'interval of zero' => [['interval_minutes' => 0], 'interval_minutes'],
]);

test('editing settings runs the monitor now; disabling stops it', function () {
    $this->actingAs($this->admin)->put(route('monitors.update', $this->monitor), [
        'arguments' => ['timeout' => 20], 'enabled' => true,
    ])->assertSessionHasNoErrors();

    expect($this->monitor->fresh()->arguments)->toBe(['timeout' => 20]);
    Queue::assertPushed(RunMonitor::class, 1);

    $this->put(route('monitors.update', $this->monitor), ['enabled' => false]);

    expect($this->monitor->fresh())->enabled->toBeFalse()->next_run_at->toBeNull()
        ->and(Activity::query()->where('type', 'monitor.disabled')->exists())->toBeTrue();
});

test('enabling a monitor resets its counters', function () {
    $this->monitor->forceFill(['enabled' => false, 'next_run_at' => null])->saveQuietly();
    $this->monitor->state->forceFill(['consecutive_failures' => 3])->save();

    $this->actingAs($this->admin)->put(route('monitors.update', $this->monitor), ['enabled' => true]);

    expect($this->monitor->state()->first()->consecutive_failures)->toBe(0)
        ->and($this->monitor->fresh()->next_run_at)->not->toBeNull();
});

test('deleting a monitor takes it out of the resource status', function () {
    $this->monitor->state->forceFill(['status' => Status::Ok])->save();
    ResourceStatus::refresh($this->site);
    expect($this->site->fresh()->status)->toBe(Status::Ok);

    $this->actingAs($this->admin)->delete(route('monitors.destroy', $this->monitor))->assertRedirect();

    expect($this->monitor->fresh()->trashed())->toBeTrue()
        ->and($this->site->fresh()->status)->toBe(Status::Unknown);
});

test('run now and run checks claim enabled monitors', function () {
    Monitor::factory()->on($this->site, 'site.path')->disabled()->create();

    $this->actingAs($this->admin)->post(route('monitors.run', $this->monitor))
        ->assertInertiaFlash('toast.type', 'success');
    $this->post(route('sites.run-checks', $this->site))
        ->assertInertiaFlash('toast.message', '1 check is running. Results show here in a few seconds.');

    Queue::assertPushed(RunMonitor::class, 2);
});
