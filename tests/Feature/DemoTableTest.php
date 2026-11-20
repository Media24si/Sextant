<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->reader = User::factory()->reader()->create(['name' => 'Mia Reader']);
});

test('it lists users sorted by name, 50 per page', function () {
    User::factory()->count(59)->create();

    $this->actingAs($this->reader)
        ->get(route('demo.table'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('demo/Table')
            ->has('rows.data', 50)
            ->where('rows.meta.current_page', 1)
            ->where('rows.meta.last_page', 2)
            ->where('rows.meta.per_page', 50)
            ->where('rows.meta.total', 60)
            ->where('filters', [])
            ->where('sort', 'name')
            ->where('rows.data.0.name', User::query()->orderBy('name')->value('name'))
            ->missing('rows.data.0.email'));
});

test('it sorts descending and by other allowed columns', function () {
    User::factory()->create(['name' => 'Ana', 'created_at' => Carbon::parse('2026-01-01')]);
    User::factory()->create(['name' => 'Zala', 'created_at' => Carbon::parse('2026-06-01')]);

    $this->actingAs($this->reader)
        ->get(route('demo.table', ['sort' => '-name']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', '-name')
            ->where('rows.data.0.name', 'Zala'));

    $this->actingAs($this->reader)
        ->get(route('demo.table', ['sort' => 'created_at']))
        ->assertInertia(fn (Assert $page) => $page->where('rows.data.0.name', 'Ana'));
});

test('it filters by role and echoes the filter', function () {
    User::factory()->admin()->count(2)->create();

    $this->actingAs($this->reader)
        ->get(route('demo.table', ['filter' => ['role' => 'admin']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 2)
            ->where('rows.data.0.role', 'admin')
            ->where('filters', ['role' => 'admin'])
            ->where('counts', ['all' => 3, 'admin' => 2, 'reader' => 1]));
});

test('it searches names', function () {
    User::factory()->create(['name' => 'Ana Novak']);
    User::factory()->create(['name' => 'Bor Kranjc']);

    $this->actingAs($this->reader)
        ->get(route('demo.table', ['filter' => ['search' => 'nova']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.name', 'Ana Novak')
            ->where('filters', ['search' => 'nova']));
});

test('later pages keep the filters and sort in their links', function () {
    User::factory()->count(60)->create();

    $this->actingAs($this->reader)
        ->get(route('demo.table', ['filter' => ['role' => 'reader'], 'sort' => '-name', 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 11)
            ->where('rows.meta.current_page', 2)
            ->where('rows.links.0.url', fn (string $url) => str_contains(urldecode($url), 'filter[role]=reader')
                && str_contains($url, 'sort=-name')));
});

test('it rejects sorts and filters that are not allowed', function () {
    $this->actingAs($this->reader)
        ->get(route('demo.table', ['sort' => 'email']))
        ->assertBadRequest();

    $this->actingAs($this->reader)
        ->get(route('demo.table', ['filter' => ['email' => 'a@b.c']]))
        ->assertBadRequest();
});

test('the admin-only action flashes a toast for admins', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->from(route('demo.table'))
        ->post(route('demo.table.ping'))
        ->assertRedirect(route('demo.table'))
        ->assertInertiaFlash('toast.type', 'success');
});
