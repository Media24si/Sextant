<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\GithubClient;
use App\Net\Results\GithubRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpGithubClient implements GithubClient
{
    public function repository(string $owner, string $name, string $token): GithubRepository
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->url($owner, $name)), $token);
        $this->failUnlessSuccessful($response, 'Repository');

        $expires = $response->header('github-authentication-token-expiration');

        try {
            $expiresAt = $expires === '' ? null : CarbonImmutable::parse($expires)->utc();
        } catch (Throwable) {
            $expiresAt = null;
        }

        return new GithubRepository(
            defaultBranch: (string) $response->json('default_branch', ''),
            archived: (bool) $response->json('archived', false),
            tokenExpiresAt: $expiresAt,
        );
    }

    public function rootFiles(string $owner, string $name, string $token): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($this->url($owner, $name).'/contents'), $token);

        // An empty repository has no contents at all.
        if ($response->status() === 404 && str_contains(strtolower((string) $response->json('message')), 'empty')) {
            return [];
        }

        $this->failUnlessSuccessful($response, 'Repository');

        $files = [];

        foreach ((array) $response->json() as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'file' && is_string($entry['name'] ?? null)) {
                $files[$entry['name']] = (int) ($entry['size'] ?? 0);
            }
        }

        return $files;
    }

    public function file(string $owner, string $name, string $path, string $token): string
    {
        $maxBytes = (int) config('sextant.github.max_bytes');
        $tooLarge = new CheckCouldNotRun("{$path} is larger than ".intdiv($maxBytes, 1024 * 1024).' MB');
        // Stops the download at the limit instead of checking after it.
        $sink = new CappedSink($maxBytes, abort: true);

        try {
            $response = $this->send(
                fn (PendingRequest $request): Response => $request
                    ->accept('application/vnd.github.raw+json')
                    ->withOptions(['sink' => $sink])
                    ->get($this->url($owner, $name).'/contents/'.implode('/', array_map(rawurlencode(...), explode('/', $path)))),
                $token,
            );
        } catch (CheckCouldNotRun $exception) {
            throw $sink->overflowed ? $tooLarge : $exception;
        }

        $this->failUnlessSuccessful($response, $path);

        if ($sink->overflowed) {
            throw $tooLarge;
        }

        return $response->body();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws CheckCouldNotRun when GitHub can't be reached
     */
    private function send(callable $call, string $token): Response
    {
        $request = Http::withUserAgent((string) config('sextant.user_agent'))
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => (string) config('sextant.github.api_version')])
            ->timeout((int) config('sextant.github.timeout'));

        try {
            return $call($request);
        } catch (ConnectionException $exception) {
            throw new CheckCouldNotRun(str_contains(strtolower($exception->getMessage()), 'timed out')
                ? 'GitHub timed out'
                : 'GitHub unreachable: '.mb_strimwidth($exception->getMessage(), 0, 200, '…'));
        } catch (Throwable $exception) {
            throw new CheckCouldNotRun('GitHub request failed: '.mb_strimwidth($exception->getMessage(), 0, 200, '…'));
        }
    }

    /**
     * @throws CheckCouldNotRun
     */
    private function failUnlessSuccessful(Response $response, string $what): void
    {
        $rateLimited = $response->header('x-ratelimit-remaining') === '0';

        match (true) {
            $response->successful() => null,
            $response->status() === 401 => throw new CheckCouldNotRun('GitHub token rejected (401): expired or revoked'),
            $response->status() === 429, $response->status() === 403 && $rateLimited => throw new CheckCouldNotRun('GitHub rate limit reached'),
            $response->status() === 403 => throw new CheckCouldNotRun("The GitHub token can't read this repository (403)"),
            $response->status() === 404 => throw new CheckCouldNotRun("{$what} not found, or the GitHub token can't read it (404)"),
            default => throw new CheckCouldNotRun("GitHub returned HTTP {$response->status()}"),
        };
    }

    private function url(string $owner, string $name): string
    {
        return rtrim((string) config('sextant.github.api_url'), '/').'/repos/'.rawurlencode($owner).'/'.rawurlencode($name);
    }
}
