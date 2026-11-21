<?php

use App\Checks\CheckResult;
use App\Enums\IncidentResolution;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Monitoring\IncidentManager;
use Inertia\Testing\AssertableInertia as Assert;

/* Incidents list and page, acknowledge, and resource roll-ups (SPEC §13.6). */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ana Novak']);
    $this->reader = User::factory()->reader()->create();
    $this->web02 = Server::factory()->create(['name' => 'web02']);
    $this->site = Site::factory()->create(['server_id' => $this->web02->id]);
    $this->manager = app(IncidentManager::class);

    $this->outage = $this->manager->open(Monitor::factory()->on($this->web02, 'server.tcp')->create(['label' => 'HTTPS']), $this->web02, CheckResult::error('Port 443 refused'), now());
    $this->child = $this->manager->open(Monitor::factory()->on($this->site, 'site.http')->create(), $this->site, CheckResult::error('Connection refused'), now());
    $this->old = Incident::factory()->resolved()->create(['severity' => Status::Warning]);
});

test('the list filters open, acknowledged and resolved incidents', function () {
    $this->manager->acknowledge($this->child, $this->admin);

    $this->actingAs($this->reader)->get(route('incidents.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('incidents/Index')
            ->where('counts', ['all' => 3, 'open' => 1, 'acknowledged' => 1, 'resolved' => 1])
            ->has('rows.data', 3));

    $this->get(route('incidents.index', ['filter' => ['state' => 'acknowledged']]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)
            ->where('rows.data.0.acknowledged_by', 'Ana Novak')
            ->where('rows.data.0.parent_id', $this->outage->id));

    $this->get(route('incidents.index', ['filter' => ['server' => (string) $this->web02->id]]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));
});

test('the incident page shows its timeline and grouped incidents', function () {
    $this->actingAs($this->reader)->get(route('incidents.show', $this->outage))
        ->assertInertia(fn (Assert $page) => $page
            ->component('incidents/Show')
            ->where('detail.incident.title', 'web02 — TCP port (HTTPS)')
            ->where('detail.incident.state', 'open')
            ->where('detail.events.0.type', 'opened')
            ->where('detail.children.0.id', $this->child->id)
            ->where('detail.first_result.message', 'Port 443 refused'));

    $this->get(route('incidents.show', $this->child))
        ->assertInertia(fn (Assert $page) => $page->where('detail.parent.id', $this->outage->id));
});

test('an admin acknowledges an open incident once', function () {
    $this->actingAs($this->admin)->post(route('incidents.acknowledge', $this->child))
        ->assertInertiaFlash('toast.type', 'success');
    $this->post(route('incidents.acknowledge', $this->child))
        ->assertInertiaFlash('toast.message', 'Already acknowledged.');
    $this->post(route('incidents.acknowledge', $this->old))
        ->assertInertiaFlash('toast.message', 'This incident is resolved.');

    expect($this->child->fresh())->acknowledged_by->toBe($this->admin->id)
        ->and($this->child->events()->where('type', 'acknowledged')->sole())->user_id->toBe($this->admin->id)->notify->toBeTrue()
        ->and(Activity::query()->where('type', 'incident.acknowledged')->sole()->user_id)->toBe($this->admin->id);
});

test('a reader can\'t acknowledge', function () {
    $this->actingAs($this->reader)->post(route('incidents.acknowledge', $this->child))->assertForbidden();

    expect($this->child->fresh()->acknowledged_at)->toBeNull();
});

test('the server page rolls up its sites\' incidents; the site page shows its own', function () {
    $this->actingAs($this->reader)->get(route('servers.show', $this->web02))
        ->assertInertia(fn (Assert $page) => $page->has('incidents', 2));

    $this->get(route('sites.show', $this->site))
        ->assertInertia(fn (Assert $page) => $page->has('incidents', 1)->where('incidents.0.id', $this->child->id));
});

test('the sidebar counts open root incidents; a child counts again once its parent resolved', function () {
    $this->actingAs($this->reader)->get(route('incidents.index'))
        ->assertInertia(fn (Assert $page) => $page->where('nav.incidents', 1));

    $this->manager->resolve($this->outage, IncidentResolution::Recovered, now());

    $this->get(route('incidents.index'))
        ->assertInertia(fn (Assert $page) => $page->where('nav.incidents', 1));

    $this->manager->resolve($this->child, IncidentResolution::Recovered, now());

    $this->get(route('incidents.index'))
        ->assertInertia(fn (Assert $page) => $page->where('nav.incidents', 0));
});

test('a monitor row links to its open incident', function () {
    $this->actingAs($this->reader)->get(route('sites.show', $this->site))
        ->assertInertia(fn (Assert $page) => $page->where('monitors.0.open_incident_id', $this->child->id));
});
