<?php

use App\Models\ExternalService;
use App\Models\Server;
use App\Models\User;

/*
 * Every route that changes data is admin only (SPEC §13.2, §17): readers get
 * 403, admins get through. Add each new admin-only route here. The third
 * value builds the route parameters once the database is ready.
 */
dataset('admin routes', [
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
]);

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
