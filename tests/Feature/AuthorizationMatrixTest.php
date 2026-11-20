<?php

use App\Models\User;

/*
 * Every route that changes data is admin only (SPEC §13.2, §17): readers get
 * 403, admins succeed. Add each new admin-only route here.
 *
 * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
 */
dataset('admin routes', [
    'demo table ping' => ['post', 'demo.table.ping'],
]);

test('readers get 403', function (string $method, string $route, array $parameters = []) {
    $this->actingAs(User::factory()->reader()->create())
        ->call(strtoupper($method), route($route, $parameters))
        ->assertForbidden();
})->with('admin routes');

test('admins are allowed', function (string $method, string $route, array $parameters = []) {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->call(strtoupper($method), route($route, $parameters));

    expect($response->status())->toBeLessThan(400);
})->with('admin routes');

test('guests are sent to the login page', function (string $method, string $route, array $parameters = []) {
    $this->call(strtoupper($method), route($route, $parameters))
        ->assertRedirect(route('login'));
})->with('admin routes');
