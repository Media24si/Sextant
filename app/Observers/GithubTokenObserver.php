<?php

namespace App\Observers;

use App\Models\GithubToken;

/**
 * `github_token.set` / `replaced` / `removed` (SPEC §8.4). Only the last four
 * characters are recorded, never the token. Sextant's own updates (expiry,
 * last check) are saved quietly and never get here.
 */
class GithubTokenObserver extends InventoryObserver
{
    public function created(GithubToken $token): void
    {
        $this->record('github_token.set', $token, "GitHub token for {$token->owner} set (…{$token->token_last4})");
    }

    public function updated(GithubToken $token): void
    {
        if (! $token->wasChanged('token_last4') && ! $token->wasChanged('token')) {
            return;
        }

        $this->record('github_token.replaced', $token, "GitHub token for {$token->owner} replaced (…{$token->token_last4})", [
            'token_last4' => ['old' => $token->getOriginal('token_last4'), 'new' => $token->token_last4],
        ]);
    }

    public function deleted(GithubToken $token): void
    {
        $this->record('github_token.removed', $token, "GitHub token for {$token->owner} removed");
    }
}
