<?php

namespace App\Observers;

use App\Models\User;

/**
 * Only the user changes the feed cares about (SPEC §8.4): created, deleted
 * and role changes. Profile and password changes aren't recorded.
 */
class UserObserver extends InventoryObserver
{
    public function created(User $user): void
    {
        $this->record('user.created', $user, "User {$user->name} added ({$user->role->value})");
    }

    public function updated(User $user): void
    {
        $changes = $this->changes($user, ['role']);

        if ($changes !== []) {
            $this->record('user.role_changed', $user, "{$user->name} is now {$changes['role']['new']} (was {$changes['role']['old']})", $changes);
        }
    }

    public function deleted(User $user): void
    {
        $this->record('user.deleted', $user, "User {$user->name} deleted");
    }
}
