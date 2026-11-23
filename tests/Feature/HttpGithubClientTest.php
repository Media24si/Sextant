<?php

use App\Checks\CheckCouldNotRun;
use App\Net\Real\HttpGithubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The real GitHub client (SPEC §11.9) against faked HTTP: what it sends, what
 * it reads, and the message for each way GitHub refuses.
 */
beforeEach(function () {
    $this->client = new HttpGithubClient;
});

it('reads the repository with the token and the expiry header', function () {
    Http::fake(['api.github.com/repos/media24/svet24' => Http::response(
        ['default_branch' => 'production', 'archived' => true],
        200,
        ['github-authentication-token-expiration' => '2027-01-31 12:00:00 UTC'],
    )]);

    $repository = $this->client->repository('media24', 'svet24', 'github_pat_secret');

    expect($repository->defaultBranch)->toBe('production')
        ->and($repository->archived)->toBeTrue()
        ->and($repository->tokenExpiresAt?->toIso8601String())->toBe('2027-01-31T12:00:00+00:00');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer github_pat_secret')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28')
        && $request->method() === 'GET');
});

it('lists only the files in the root, and nothing for an empty repository', function () {
    Http::fake([
        'api.github.com/repos/media24/svet24/contents' => Http::response([
            ['name' => 'composer.lock', 'type' => 'file', 'size' => 512],
            ['name' => 'app', 'type' => 'dir', 'size' => 0],
        ]),
        'api.github.com/repos/media24/empty/contents' => Http::response(['message' => 'This repository is empty.'], 404),
    ]);

    expect($this->client->rootFiles('media24', 'svet24', 't'))->toBe(['composer.lock' => 512])
        ->and($this->client->rootFiles('media24', 'empty', 't'))->toBe([]);
});

it('downloads a file raw', function () {
    Http::fake(['api.github.com/repos/media24/svet24/contents/composer.lock' => Http::response('{"packages":[]}')]);

    expect($this->client->file('media24', 'svet24', 'composer.lock', 't'))->toBe('{"packages":[]}');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept', 'application/vnd.github.raw+json'));
});

it('explains each way GitHub refuses', function (int $status, array $headers, string $message) {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'nope'], $status, $headers)]);

    expect(fn () => $this->client->repository('media24', 'svet24', 't'))
        ->toThrow(CheckCouldNotRun::class, $message);
})->with([
    'revoked token' => [401, [], 'GitHub token rejected (401): expired or revoked'],
    'rate limit' => [403, ['x-ratelimit-remaining' => '0'], 'GitHub rate limit reached'],
    'secondary rate limit' => [429, [], 'GitHub rate limit reached'],
    'no permission' => [403, [], "The GitHub token can't read this repository (403)"],
    'not selected for the token' => [404, [], "Repository not found, or the GitHub token can't read it (404)"],
    'outage' => [502, [], 'GitHub returned HTTP 502'],
]);
