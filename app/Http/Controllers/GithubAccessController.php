<?php

namespace App\Http\Controllers;

use App\Checks\CheckCouldNotRun;
use App\Data\GithubOwnerRowData;
use App\Data\GithubRepositoryTestData;
use App\Data\GithubTestResultData;
use App\Github\GithubRepo;
use App\Http\Requests\GithubTokenRequest;
use App\Models\GithubToken;
use App\Net\GithubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GitHub access (SPEC §11.9, §13.6): one read-only token per owner of a
 * linked repository, and a Test that shows which repositories it can read.
 * Admins only; tokens never reach the browser.
 */
class GithubAccessController extends Controller
{
    public function index(): Response
    {
        $repositories = GithubRepo::linked()->groupBy(fn (GithubRepo $repo): string => strtolower($repo->owner));
        $tokens = GithubToken::query()->with('setBy')->get()->keyBy(fn (GithubToken $token): string => strtolower($token->owner));

        // A numeric owner name becomes an integer key; owners are names.
        $owners = $repositories->keys()->merge($tokens->keys())->map(fn (int|string $owner): string => (string) $owner)->unique()->sort()->values();

        return Inertia::render('github-access/Index', [
            'owners' => $owners->map(fn (string $owner): GithubOwnerRowData => GithubOwnerRowData::forOwner(
                $repositories->get($owner)?->first()->owner ?? $owner,
                array_values($repositories->get($owner, new Collection)->map(fn (GithubRepo $repo): string => $repo->fullName())->all()),
                $tokens->get($owner),
            ))->all(),
            'expiry_warning_days' => (int) config('sextant.github.expiry_warning_days'),
        ]);
    }

    /** Set or replace the token of an owner. */
    public function store(GithubTokenRequest $request): RedirectResponse
    {
        /** @var array{owner: string, token: string} $validated */
        $validated = $request->validated();
        $token = GithubToken::forOwner($validated['owner']) ?? new GithubToken(['owner' => $validated['owner']]);
        $replacing = $token->exists;

        $token->setToken($validated['token']);
        $token->set_by = (int) $request->user()?->id;
        $token->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => ($replacing ? 'Token replaced' : 'Token saved')." for {$token->owner}. Press Test to check which repositories it can read."]);

        return back();
    }

    public function destroy(GithubToken $githubToken): RedirectResponse
    {
        $githubToken->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Token for {$githubToken->owner} removed."]);

        return back();
    }

    /**
     * Read each linked repository of the owner and list its root lock files.
     * Remembers the token's expiry and the first error.
     */
    public function test(GithubToken $githubToken, GithubClient $client): JsonResponse
    {
        $repositories = GithubRepo::linked()->filter(fn (GithubRepo $repo): bool => strcasecmp($repo->owner, $githubToken->owner) === 0)->values();

        if ($repositories->isEmpty()) {
            return response()->json(new GithubTestResultData(ok: false, error: "No site or application links a repository of {$githubToken->owner}."));
        }

        $lockFiles = (array) config('sextant.github.lock_files');
        $expiresAt = null;
        $results = [];

        foreach ($repositories as $repo) {
            try {
                $repository = $client->repository($repo->owner, $repo->name, $githubToken->token);
                $expiresAt ??= $repository->tokenExpiresAt;
                $found = array_values(array_intersect($lockFiles, array_keys($client->rootFiles($repo->owner, $repo->name, $githubToken->token))));
                $results[] = new GithubRepositoryTestData($repo->fullName(), true, default_branch: $repository->defaultBranch, lock_files: $found);
            } catch (CheckCouldNotRun $exception) {
                $results[] = new GithubRepositoryTestData($repo->fullName(), false, error: $exception->getMessage());
            }
        }

        $firstError = collect($results)->first(fn (GithubRepositoryTestData $result): bool => ! $result->ok)?->error;

        $githubToken->forceFill([
            'checked_at' => now(),
            'expires_at' => $expiresAt ?? $githubToken->expires_at,
            'last_error' => $firstError === null ? null : mb_strimwidth($firstError, 0, 255, '…'),
        ])->saveQuietly();

        return response()->json(new GithubTestResultData(
            ok: $firstError === null,
            expires_at: $githubToken->expires_at?->toIso8601String(),
            repositories: $results,
        ));
    }
}
