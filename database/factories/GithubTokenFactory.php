<?php

namespace Database\Factories;

use App\Models\GithubToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubToken>
 */
class GithubTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'github_pat_'.fake()->regexify('[A-Za-z0-9]{22}_[A-Za-z0-9]{59}');

        return [
            'owner' => 'media24',
            'token' => $token,
            'token_last4' => substr($token, -4),
            'set_by' => User::factory()->admin(),
        ];
    }
}
