<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ana Novak']);
});

test('admins see every user with their role', function () {
    User::factory()->reader()->create(['name' => 'Bor Kranjc']);

    $this->actingAs($this->admin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Index')
            ->where('rows.data.0.name', 'Ana Novak')
            ->where('rows.data.0.is_you', true)
            ->where('rows.data.1.role', 'reader')
            ->where('counts', ['all' => 2, 'admin' => 1, 'reader' => 1]));
});

test('an admin adds a user with a first password', function () {
    $this->actingAs($this->admin)
        ->post(route('users.store'), ['name' => 'Bor Kranjc', 'email' => 'Bor@Media24.si', 'role' => 'admin', 'password' => 'a-long-password'])
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'bor@media24.si')->sole();

    expect($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue();

    $this->post(route('users.store'), ['name' => 'Again', 'email' => 'bor@media24.si', 'role' => 'reader', 'password' => 'a-long-password'])
        ->assertSessionHasErrors('email');
});

test('roles change, but the last admin stays an admin', function () {
    $reader = User::factory()->reader()->create();

    $this->actingAs($this->admin)->put(route('users.role', $reader), ['role' => 'admin']);
    expect($reader->fresh()->role)->toBe(UserRole::Admin);

    $this->put(route('users.role', $reader), ['role' => 'reader']);
    expect($reader->fresh()->role)->toBe(UserRole::Reader);

    $this->put(route('users.role', $this->admin), ['role' => 'reader'])
        ->assertInertiaFlash('toast.message', 'Ana Novak is the last admin. Make someone else an admin first.');
    expect($this->admin->fresh()->role)->toBe(UserRole::Admin);
});

test('users are soft-deleted, and the last admin never is', function () {
    $reader = User::factory()->reader()->create();

    $this->actingAs($this->admin)->delete(route('users.destroy', $reader));
    expect($reader->fresh()->trashed())->toBeTrue();

    $this->delete(route('users.destroy', $this->admin))
        ->assertInertiaFlash('toast.message', "Ana Novak is the last admin and can't be deleted.");
    expect($this->admin->fresh()->trashed())->toBeFalse();
});

test('an admin who deletes themselves is signed out', function () {
    User::factory()->admin()->create();

    $this->actingAs($this->admin)
        ->delete(route('users.destroy', $this->admin))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($this->admin->fresh()->trashed())->toBeTrue();
});
