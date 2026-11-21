<?php

use App\Enums\Status;
use App\Models\Activity;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Applications (SPEC §3.19): one web app behind several hostnames, each still
 * its own site on its own domain.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('readers see applications with their hostnames and worst site status', function () {
    $backend = Application::factory()->create(['name' => 'Backend']);
    Site::factory()->for(Domain::factory()->create(['name' => 'svet24.si']))->for($backend)->create(['hostname' => 'backend.svet24.si']);
    Site::factory()->for(Domain::factory()->create(['name' => 'reporter.si']))->for($backend)->create(['hostname' => 'backend.reporter.si'])
        ->forceFill(['status' => Status::Error])->saveQuietly();
    Application::factory()->create(['name' => 'Kviz']);

    $this->actingAs(User::factory()->reader()->create())
        ->get(route('applications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('applications/Index')
            ->has('rows.data', 2)
            ->where('rows.data.0.name', 'Backend')
            ->where('rows.data.0.hostnames', ['backend.reporter.si', 'backend.svet24.si'])
            ->where('rows.data.0.sites_count', 2)
            ->where('rows.data.0.status', 'error')
            ->where('rows.data.1.name', 'Kviz')
            ->where('rows.data.1.sites_count', 0));
});

test('an admin adds an application and it reaches the change feed', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store'), [
            'name' => ' Backend ',
            'description' => 'Editorial backend',
            'github_url' => 'https://github.com/media24/backend/',
            'notes' => null,
        ])
        ->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application)
        ->name->toBe('Backend')
        ->github_url->toBe('https://github.com/media24/backend')
        ->and(Activity::query()->where('type', 'application.created')->sole()->summary)->toBe('Application Backend added');
});

test('application input is validated', function (array $input, string $error, string $message) {
    Application::factory()->create(['name' => 'Backend']);

    $this->actingAs($this->admin)
        ->post(route('applications.store'), ['name' => 'Kviz', ...$input])
        ->assertSessionHasErrors([$error => $message]);
})->with([
    'no name' => [['name' => ''], 'name', 'The name field is required.'],
    'name taken' => [['name' => 'Backend'], 'name', 'The name has already been taken.'],
    'not a repository' => [['github_url' => 'https://github.com/media24'], 'github_url', 'The GitHub repository must be a GitHub repository link like https://github.com/media24/svet24.'],
]);

test('an admin renames an application', function () {
    $application = Application::factory()->create(['name' => 'Backend']);

    $this->actingAs($this->admin)
        ->put(route('applications.update', $application), ['name' => 'Media24 backend'])
        ->assertRedirect(route('applications.show', $application));

    expect($application->fresh()->name)->toBe('Media24 backend')
        ->and(Activity::query()->where('type', 'application.updated')->sole()->summary)->toBe('Application Media24 backend changed: name');
});

test('the application page lists its hostnames and their changes', function () {
    $application = Application::factory()->create(['name' => 'Backend']);
    $site = Site::factory()->for(Domain::factory()->create(['name' => 'svet24.si']))->create(['hostname' => 'backend.svet24.si']);
    $this->actingAs($this->admin)->put(route('sites.update', $site), [
        'domain_id' => $site->domain_id,
        'application_id' => $application->id,
        'hostname' => 'backend.svet24.si',
        'scheme' => 'https',
    ])->assertSessionHasNoErrors();

    $this->actingAs(User::factory()->reader()->create())
        ->get(route('applications.show', $application))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('applications/Show')
            ->has('sites', 1)
            ->where('sites.0.hostname', 'backend.svet24.si')
            ->where('sites.0.domain', 'svet24.si')
            ->where('sites.0.application', 'Backend')
            ->where('activity.0.summary', 'Site backend.svet24.si changed: application_id'));
});

test('an application with sites is not deleted; an empty one is, and comes back', function () {
    $application = Application::factory()->create(['name' => 'Backend']);
    $site = Site::factory()->for($application)->create();

    $this->actingAs($this->admin)
        ->from(route('applications.show', $application))
        ->delete(route('applications.destroy', $application))
        ->assertInertiaFlash('toast.message', 'Backend still has 1 site. Move them to another application or clear it first.');
    expect($application->fresh()->trashed())->toBeFalse();

    $site->update(['application_id' => null]);
    $this->delete(route('applications.destroy', $application))->assertRedirect(route('applications.index'));
    expect($application->fresh()->trashed())->toBeTrue();

    $this->post(route('applications.restore', $application))->assertRedirect(route('applications.show', $application));
    expect($application->fresh()->trashed())->toBeFalse();
});

test('an application is not restored when its name is taken again', function () {
    $application = tap(Application::factory()->create(['name' => 'Backend']))->delete();
    Application::factory()->create(['name' => 'Backend']);

    $this->actingAs($this->admin)
        ->from(route('applications.index'))
        ->post(route('applications.restore', $application))
        ->assertInertiaFlash('toast.message', "Can't restore Backend: another application has that name.");

    expect($application->fresh()->trashed())->toBeTrue();
});
