<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates an admin. There is no public registration (SPEC §3.1); the first
 * admin comes from here and admins create everyone else.
 */
#[Signature('sextant:create-admin')]
#[Description('Create an admin user')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = text(
            label: 'Name',
            required: true,
            validate: ['name' => ['required', 'string', 'max:255']],
        );

        $email = Str::lower(text(
            label: 'Email',
            required: true,
            validate: fn (string $value): ?string => $this->firstError('email', Str::lower($value), [
                'required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email'),
            ]),
        ));

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value): ?string => $this->firstError('password', $value, [
                'required', 'string', Password::defaults(),
            ]),
        );

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = UserRole::Admin;
        $user->save();

        $this->components->info("Admin {$user->email} created.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function firstError(string $attribute, string $value, array $rules): ?string
    {
        return Validator::make([$attribute => $value], [$attribute => $rules])
            ->errors()
            ->first($attribute) ?: null;
    }
}
