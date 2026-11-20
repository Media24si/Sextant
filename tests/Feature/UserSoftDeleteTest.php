<?php

use App\Models\Activity;
use App\Models\Domain;
use App\Models\User;

test('a deleted user can no longer log in', function () {
    $user = User::factory()->create(['email' => 'ana@media24.si']);
    $user->delete();

    $this->post(route('login.store'), ['email' => 'ana@media24.si', 'password' => 'password']);

    $this->assertGuest();
});

test('a signed-in user who is deleted is signed out', function () {
    $user = User::factory()->create(['email' => 'ana@media24.si']);
    $this->post(route('login.store'), ['email' => 'ana@media24.si', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    $user->delete();
    // The next request loads the user from the session again, as a real one would.
    $this->app['auth']->forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a deleted user keeps the name on their past changes', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ana Novak']);
    $this->actingAs($admin);
    Domain::factory()->create();

    $admin->delete();

    expect(Activity::query()->where('type', 'domain.created')->sole()->user?->name)->toBe('Ana Novak');
});

test("a deleted user's email can be used for a new account", function () {
    User::factory()->create(['email' => 'ana@media24.si'])->delete();

    $this->artisan('sextant:create-admin')
        ->expectsQuestion('Name', 'Ana Novak')
        ->expectsQuestion('Email', 'ana@media24.si')
        ->expectsQuestion('Password', 'a-long-password')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ana@media24.si')->count())->toBe(1)
        ->and(User::withTrashed()->where('email', 'ana@media24.si')->count())->toBe(2);
});
