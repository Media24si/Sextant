<?php

namespace App\Data;

use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * The fields of the user's own profile form (settings/Profile).
 */
class ProfileData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self($user->name, $user->email);
    }
}
