<?php

namespace App\Data\Repositories;

use App\Enums\Status;
use App\Models\GithubToken;
use App\Models\Repository;
use Spatie\LaravelData\Data;

/**
 * A repository in the Repositories list and on its page (SPEC §13.6).
 * Expects `packages_count` loaded.
 */
class RepositoryRowData extends Data
{
    public function __construct(
        public int $id,
        public string $owner,
        public string $name,
        /** "owner/name" */
        public string $full_name,
        public string $url,
        public Status $status,
        public ?string $default_branch,
        /** @var list<string> */
        public array $lock_files,
        public ?string $read_at,
        public int $packages_count,
        public int $below_count,
        /** @var list<RepositoryUserData> */
        public array $used_by,
        public bool $has_token,
        public ?string $token_expires_at,
        public bool $deleted,
    ) {}

    /**
     * @param  list<RepositoryUserData>  $usedBy
     */
    public static function fromModel(Repository $repository, int $belowCount, array $usedBy, ?GithubToken $token): self
    {
        return new self(
            id: $repository->id,
            owner: $repository->owner,
            name: $repository->name,
            full_name: $repository->fullName(),
            url: $repository->url,
            status: $repository->status,
            default_branch: $repository->default_branch,
            lock_files: $repository->lock_files ?? [],
            read_at: $repository->read_at?->toIso8601String(),
            packages_count: (int) $repository->getAttribute('packages_count'),
            below_count: $belowCount,
            used_by: $usedBy,
            has_token: $token !== null,
            token_expires_at: $token?->expires_at?->toIso8601String(),
            deleted: $repository->trashed(),
        );
    }
}
