<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'admin'])
        ->post('/_test/admin-only', fn () => response('changed'));
});

test('new users are readers unless given another role', function () {
    $user = User::factory()->make();
    $attributes = $user->getAttributes();
    unset($attributes['role']);

    $id = User::query()->insertGetId($attributes);

    expect(User::query()->find($id)->role)->toBe(UserRole::Reader);
});

test('admins pass the admin middleware', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post('/_test/admin-only')
        ->assertOk();
});

test('readers get 403 from the admin middleware', function () {
    $this->actingAs(User::factory()->reader()->create())
        ->post('/_test/admin-only')
        ->assertForbidden();
});

test('guests are sent to the login page by admin routes', function () {
    $this->post('/_test/admin-only')->assertRedirect(route('login'));
});

test('readers get the user id, name and role and cannot manage', function () {
    $reader = User::factory()->reader()->create();

    $this->actingAs($reader)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', ['id' => $reader->id, 'name' => $reader->name, 'role' => 'reader'])
            ->where('can.manage', false));
});

test('admins can manage', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.role', 'admin')
            ->where('can.manage', true));
});
