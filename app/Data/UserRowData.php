<?php

namespace App\Data;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\LaravelData\Data;

/** A row of the Users page (SPEC §13.6). Admins only. */
class UserRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public UserRole $role,
        public ?string $created_at,
        public bool $is_you,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->role,
            created_at: $user->created_at?->toAtomString(),
            is_you: $user->id === auth()->id(),
        );
    }
}
