<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates an admin', function () {
    $this->artisan('sextant:create-admin')
        ->expectsQuestion('Name', 'Ana Novak')
        ->expectsQuestion('Email', 'Ana@Media24.si')
        ->expectsQuestion('Password', 'a-long-password')
        ->expectsOutputToContain('Admin ana@media24.si created.')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->name)->toBe('Ana Novak')
        ->and($user->email)->toBe('ana@media24.si')
        ->and($user->role)->toBe(UserRole::Admin)
        ->and(Hash::check('a-long-password', $user->password))->toBeTrue();
});

// In tests Laravel stops at the first invalid answer instead of asking again,
// so these check the error and that nothing was created.
test('it rejects an email that is already taken, ignoring case', function () {
    User::factory()->create(['email' => 'ana@media24.si']);

    $this->artisan('sextant:create-admin')
        ->expectsQuestion('Name', 'Ana Novak')
        ->expectsQuestion('Email', 'ANA@media24.si')
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

test('it rejects a password that is too short', function () {
    $this->artisan('sextant:create-admin')
        ->expectsQuestion('Name', 'Ana Novak')
        ->expectsQuestion('Email', 'ana@media24.si')
        ->expectsQuestion('Password', 'short')
        ->expectsOutputToContain('at least 8 characters')
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse();
});
