<?php

use App\Models\User;

test('admins can open Horizon', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/horizon')
        ->assertOk();
});

test('readers get 403 from Horizon', function () {
    $this->actingAs(User::factory()->reader()->create())
        ->get('/horizon')
        ->assertForbidden();
});

test('guests get 403 from Horizon', function () {
    $this->get('/horizon')->assertForbidden();
});

test('Horizon stays admin only in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->actingAs(User::factory()->reader()->create())
        ->get('/horizon')
        ->assertForbidden();
});
