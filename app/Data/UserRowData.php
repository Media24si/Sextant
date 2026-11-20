<?php

namespace App\Data;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A row of the temporary demo table (P0.11). Leaves out email: the users
 * list itself is admin only (SPEC §13.3).
 */
class UserRowData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public UserRole $role,
        public ?CarbonInterface $created_at,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self($user->id, $user->name, $user->role, $user->created_at);
    }
}
