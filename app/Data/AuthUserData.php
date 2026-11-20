<?php

namespace App\Data;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * The signed-in user as shared with every page (`auth.user`, SPEC §13.2).
 */
class AuthUserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public UserRole $role,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self($user->id, $user->name, $user->role);
    }
}
