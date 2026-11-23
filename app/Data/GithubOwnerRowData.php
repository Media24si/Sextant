<?php

namespace App\Data;

use App\Models\GithubToken;
use Spatie\LaravelData\Data;

/** A row of the GitHub access page (SPEC §13.6): an owner, its linked repositories and its token. */
class GithubOwnerRowData extends Data
{
    public function __construct(
        public string $owner,
        /** @var list<string> "owner/name" of each linked repository */
        public array $repositories,
        public ?int $token_id,
        /** "github_pat_••••a91f"; the token itself never reaches the browser */
        public ?string $token_masked,
        public ?string $expires_at,
        public ?string $checked_at,
        public ?string $last_error,
        public ?string $set_by,
        public ?string $updated_at,
    ) {}

    /**
     * @param  list<string>  $repositories
     */
    public static function forOwner(string $owner, array $repositories, ?GithubToken $token): self
    {
        return new self(
            owner: $token->owner ?? $owner,
            repositories: $repositories,
            token_id: $token?->id,
            token_masked: $token?->masked(),
            expires_at: $token?->expires_at?->toIso8601String(),
            checked_at: $token?->checked_at?->toIso8601String(),
            last_error: $token?->last_error,
            set_by: $token?->setBy->name,
            updated_at: $token === null ? null : ($token->updated_at ?? $token->created_at)?->toIso8601String(),
        );
    }
}
