<?php

namespace App\Net\Fake;

use App\Checks\CheckCouldNotRun;
use App\Net\GithubClient;
use App\Net\Results\GithubRepository;

/**
 * Canned repositories for tests, by `owner/name` (case-insensitive). Unknown
 * repositories answer 404, like GitHub does for a token that can't see them.
 */
class FakeGithubClient implements GithubClient
{
    /** @var array<string, array{repository: GithubRepository, files: array<string, string>, token: string|null}|CheckCouldNotRun> */
    private array $repositories = [];

    /** @var list<array{call: string, repo: string, path: string|null, token: string}> */
    public array $requests = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(GithubClient::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, string>  $files  root files by name
     * @param  string|null  $token  only this token may read it; null = any
     */
    public function repo(string $fullName, array $files = [], ?string $token = null, ?GithubRepository $repository = null): self
    {
        $this->repositories[strtolower($fullName)] = [
            'repository' => $repository ?? new GithubRepository('main', false),
            'files' => $files,
            'token' => $token,
        ];

        return $this;
    }

    public function fail(string $fullName, string $message): self
    {
        $this->repositories[strtolower($fullName)] = new CheckCouldNotRun($message);

        return $this;
    }

    public function repository(string $owner, string $name, string $token): GithubRepository
    {
        return $this->find('repository', $owner, $name, null, $token)['repository'];
    }

    public function rootFiles(string $owner, string $name, string $token): array
    {
        return array_map(strlen(...), $this->find('rootFiles', $owner, $name, null, $token)['files']);
    }

    public function file(string $owner, string $name, string $path, string $token): string
    {
        $files = $this->find('file', $owner, $name, $path, $token)['files'];

        if (! isset($files[$path])) {
            throw new CheckCouldNotRun("{$path} not found, or the GitHub token can't read it (404)");
        }

        if (strlen($files[$path]) > (int) config('sextant.github.max_bytes')) {
            throw new CheckCouldNotRun("{$path} is larger than ".intdiv((int) config('sextant.github.max_bytes'), 1024 * 1024).' MB');
        }

        return $files[$path];
    }

    /**
     * @return array{repository: GithubRepository, files: array<string, string>, token: string|null}
     */
    private function find(string $call, string $owner, string $name, ?string $path, string $token): array
    {
        $fullName = strtolower("{$owner}/{$name}");
        $this->requests[] = ['call' => $call, 'repo' => $fullName, 'path' => $path, 'token' => $token];
        $repository = $this->repositories[$fullName] ?? new CheckCouldNotRun("Repository not found, or the GitHub token can't read it (404)");

        if ($repository instanceof CheckCouldNotRun) {
            throw $repository;
        }

        if ($repository['token'] !== null && $repository['token'] !== $token) {
            throw new CheckCouldNotRun('GitHub token rejected (401): expired or revoked');
        }

        return $repository;
    }
}
