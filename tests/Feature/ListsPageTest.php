<?php

use App\Enums\ChoiceList;
use App\Models\Choice;
use App\Models\Server;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/**
 * @return list<string>
 */
function osLabels(): array
{
    return Choice::ofList(ChoiceList::Os)->pluck('label')->all();
}

test('everyone sees both lists in order, with how many servers use each entry', function () {
    $ubuntu = Choice::ofList(ChoiceList::Os)->where('label', 'Ubuntu 24.04 LTS')->sole();
    Server::factory()->count(2)->create(['os_choice_id' => $ubuntu->id]);

    $this->actingAs(User::factory()->reader()->create())
        ->get(route('lists.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lists/Index')
            ->has('lists.os', 9)
            ->has('lists.backup_location', 4)
            ->where('lists.os.1.label', 'Ubuntu 24.04 LTS')
            ->where('lists.os.1.servers_count', 2)
            ->where('lists.os.0.servers_count', 0));
});

test('an admin adds an entry at the end of its list', function () {
    $this->actingAs($this->admin)
        ->post(route('lists.store'), ['list' => 'os', 'label' => 'AlmaLinux 9'])
        ->assertSessionHasNoErrors();

    expect(osLabels())->toHaveCount(10)
        ->and(osLabels()[9])->toBe('AlmaLinux 9');

    $this->post(route('lists.store'), ['list' => 'os', 'label' => 'AlmaLinux 9'])
        ->assertSessionHasErrors('label');

    // The same label is fine in the other list.
    $this->post(route('lists.store'), ['list' => 'backup_location', 'label' => 'AlmaLinux 9'])
        ->assertSessionHasNoErrors();
});

test('entries move up and down and stop at the ends', function () {
    $first = Choice::ofList(ChoiceList::Os)->firstOrFail();

    $this->actingAs($this->admin)->post(route('lists.move', $first), ['direction' => 'up']);
    expect(osLabels()[0])->toBe('Ubuntu 22.04 LTS');

    $this->post(route('lists.move', $first), ['direction' => 'down']);
    expect(array_slice(osLabels(), 0, 2))->toBe(['Ubuntu 24.04 LTS', 'Ubuntu 22.04 LTS']);
});

test('entries in use can be renamed but not deleted', function () {
    $snapshots = Choice::ofList(ChoiceList::BackupLocation)->firstOrFail();
    Server::factory()->create()->backupLocations()->attach($snapshots);

    $this->actingAs($this->admin)
        ->put(route('lists.update', $snapshots), ['label' => 'Hetzner snapshots'])
        ->assertSessionHasNoErrors();
    expect($snapshots->fresh()->label)->toBe('Hetzner snapshots');

    $this->delete(route('lists.destroy', $snapshots))
        ->assertInertiaFlash('toast.message', 'Hetzner snapshots is in use, so it can only be renamed.');
    expect($snapshots->fresh())->not->toBeNull();

    $unused = Choice::ofList(ChoiceList::BackupLocation)->where('label', 'Local disk on the server')->sole();
    $this->delete(route('lists.destroy', $unused));
    expect($unused->fresh())->toBeNull();
});

test('an entry used only by a deleted server still counts as in use', function () {
    $os = Choice::ofList(ChoiceList::Os)->firstOrFail();
    Server::factory()->create(['os_choice_id' => $os->id])->delete();

    $this->actingAs($this->admin)->delete(route('lists.destroy', $os));

    expect($os->fresh())->not->toBeNull();
});
