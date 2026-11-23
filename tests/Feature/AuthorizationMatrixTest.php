<?php

use App\Enums\ChoiceList;
use App\Models\Application;
use App\Models\Choice;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\GithubToken;
use App\Models\Incident;
use App\Models\MinimumVersion;
use App\Models\Monitor;
use App\Models\PackageMinimum;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * Every route that changes data is admin only (SPEC §13.2, §17): readers get
 * 403, admins get through. Add each new admin-only route here. The third
 * value builds the route parameters once the database is ready.
 */
$adminRoutes = [
    'add server form' => ['get', 'servers.create', null],
    'store server' => ['post', 'servers.store', null],
    'edit server form' => ['get', 'servers.edit', fn () => ['server' => Server::factory()->create()]],
    'update server' => ['put', 'servers.update', fn () => ['server' => Server::factory()->create()]],
    'delete server' => ['delete', 'servers.destroy', fn () => ['server' => Server::factory()->create()]],
    'restore server' => ['post', 'servers.restore', fn () => ['server' => tap(Server::factory()->create())->delete()]],
    'add external service form' => ['get', 'external-services.create', null],
    'store external service' => ['post', 'external-services.store', null],
    'edit external service form' => ['get', 'external-services.edit', fn () => ['external_service' => ExternalService::factory()->create()]],
    'update external service' => ['put', 'external-services.update', fn () => ['external_service' => ExternalService::factory()->create()]],
    'delete external service' => ['delete', 'external-services.destroy', fn () => ['external_service' => ExternalService::factory()->create()]],
    'restore external service' => ['post', 'external-services.restore', fn () => ['external_service' => tap(ExternalService::factory()->create())->delete()]],
    'add domain form' => ['get', 'domains.create', null],
    'store domain' => ['post', 'domains.store', null],
    'look up domain' => ['post', 'domains.lookup', null],
    'edit domain form' => ['get', 'domains.edit', fn () => ['domain' => Domain::factory()->create()]],
    'update domain' => ['put', 'domains.update', fn () => ['domain' => Domain::factory()->create()]],
    'delete domain' => ['delete', 'domains.destroy', fn () => ['domain' => Domain::factory()->create()]],
    'restore domain' => ['post', 'domains.restore', fn () => ['domain' => tap(Domain::factory()->create())->delete()]],
    'add site form' => ['get', 'sites.create', null],
    'store site' => ['post', 'sites.store', null],
    'edit site form' => ['get', 'sites.edit', fn () => ['site' => Site::factory()->create()]],
    'update site' => ['put', 'sites.update', fn () => ['site' => Site::factory()->create()]],
    'delete site' => ['delete', 'sites.destroy', fn () => ['site' => Site::factory()->create()]],
    'restore site' => ['post', 'sites.restore', fn () => ['site' => tap(Site::factory()->create())->delete()]],
    'detect site' => ['post', 'sites.detect', null],
    'detect site again' => ['post', 'sites.detect-again', fn () => ['site' => Site::factory()->create()]],
    'apply serving path' => ['put', 'sites.path', fn () => ['site' => Site::factory()->create()]],
    'import page' => ['get', 'sites.import', null],
    'import a URL' => ['post', 'sites.import.store', null],
    'add monitor' => ['post', 'monitors.store', null],
    'edit monitor' => ['put', 'monitors.update', fn () => ['monitor' => Monitor::factory()->create()]],
    'delete monitor' => ['delete', 'monitors.destroy', fn () => ['monitor' => Monitor::factory()->create()]],
    'run monitor' => ['post', 'monitors.run', fn () => ['monitor' => Monitor::factory()->create()]],
    'run server checks' => ['post', 'servers.run-checks', fn () => ['server' => Server::factory()->create()]],
    'run domain checks' => ['post', 'domains.run-checks', fn () => ['domain' => Domain::factory()->create()]],
    'run site checks' => ['post', 'sites.run-checks', fn () => ['site' => Site::factory()->create()]],
    'mute monitor' => ['post', 'monitors.mute', fn () => ['monitor' => Monitor::factory()->create()]],
    'mute server' => ['post', 'servers.mute', fn () => ['server' => Server::factory()->create()]],
    'mute domain' => ['post', 'domains.mute', fn () => ['domain' => Domain::factory()->create()]],
    'mute site' => ['post', 'sites.mute', fn () => ['site' => Site::factory()->create()]],
    'acknowledge incident' => ['post', 'incidents.acknowledge', fn () => ['incident' => Incident::factory()->create()]],
    'set minimum version' => ['post', 'minimum-versions.store', null],
    'change minimum version' => ['put', 'minimum-versions.update', fn () => ['minimum_version' => MinimumVersion::factory()->create()]],
    'remove minimum version' => ['delete', 'minimum-versions.destroy', fn () => ['minimum_version' => MinimumVersion::factory()->create()]],
    'set package minimum' => ['post', 'package-minimums.store', null],
    'change package minimum' => ['put', 'package-minimums.update', fn () => ['package_minimum' => PackageMinimum::factory()->create()]],
    'remove package minimum' => ['delete', 'package-minimums.destroy', fn () => ['package_minimum' => PackageMinimum::factory()->create()]],
    'read repository now' => ['post', 'repositories.run-checks', fn () => ['repository' => Repository::factory()->create()]],
    'generate versions token' => ['post', 'servers.versions-token', fn () => ['server' => Server::factory()->create()]],
    'copy versions install command' => ['post', 'servers.versions.install-command', fn () => ['server' => Server::factory()->create()]],
    'download collector' => ['get', 'servers.collector', fn () => ['server' => Server::factory()->create()]],
    'download versions.php' => ['get', 'servers.versions-php', fn () => ['server' => Server::factory()->create()]],
    'test versions connection' => ['post', 'servers.versions.test', fn () => ['server' => Server::factory()->create()]],
    'refresh versions' => ['post', 'servers.versions.refresh', fn () => ['server' => Server::factory()->create()]],
    'add list entry' => ['post', 'lists.store', null],
    'rename list entry' => ['put', 'lists.update', fn () => ['choice' => Choice::ofList(ChoiceList::Os)->firstOrFail()]],
    'move list entry' => ['post', 'lists.move', fn () => ['choice' => Choice::ofList(ChoiceList::Os)->firstOrFail()]],
    'delete list entry' => ['delete', 'lists.destroy', fn () => ['choice' => Choice::ofList(ChoiceList::Os)->firstOrFail()]],
    'GitHub access page' => ['get', 'github-access.index', null],
    'set GitHub token' => ['post', 'github-tokens.store', null],
    'remove GitHub token' => ['delete', 'github-tokens.destroy', fn () => ['github_token' => GithubToken::factory()->create()]],
    'test GitHub token' => ['post', 'github-tokens.test', fn () => ['github_token' => GithubToken::factory()->create()]],
    'users page' => ['get', 'users.index', null],
    'add application form' => ['get', 'applications.create', null],
    'store application' => ['post', 'applications.store', null],
    'edit application form' => ['get', 'applications.edit', fn () => ['application' => Application::factory()->create()]],
    'update application' => ['put', 'applications.update', fn () => ['application' => Application::factory()->create()]],
    'delete application' => ['delete', 'applications.destroy', fn () => ['application' => Application::factory()->create()]],
    'restore application' => ['post', 'applications.restore', fn () => ['application' => tap(Application::factory()->create())->delete()]],
    'add user' => ['post', 'users.store', null],
    'change role' => ['put', 'users.role', fn () => ['user' => User::factory()->create()]],
    'delete user' => ['delete', 'users.destroy', fn () => ['user' => User::factory()->create()]],
];

dataset('admin routes', $adminRoutes);

test('readers get 403', function (string $method, string $route, ?Closure $parameters) {
    $this->actingAs(User::factory()->reader()->create())
        ->call(strtoupper($method), route($route, $parameters ? $parameters() : []))
        ->assertForbidden();
})->with('admin routes');

test('admins are allowed', function (string $method, string $route, ?Closure $parameters) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->call(strtoupper($method), route($route, $parameters ? $parameters() : []));

    expect($response->status())->toBeLessThan(400);
})->with('admin routes');

test('guests are sent to the login page', function (string $method, string $route, ?Closure $parameters) {
    $this->call(strtoupper($method), route($route, $parameters ? $parameters() : []))
        ->assertRedirect(route('login'));
})->with('admin routes');

test('every admin-only route is in the matrix', function () use ($adminRoutes) {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('admin', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())
        ->sort()
        ->values()
        ->all();

    $covered = collect($adminRoutes)->map(fn (array $row) => $row[1])->unique()->sort()->values()->all();

    expect($covered)->toBe($registered);
});
