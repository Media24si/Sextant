<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;
use App\Net\Results\GithubRepository;

/**
 * Reads repositories through the GitHub REST API with a read-only token
 * (SPEC §11.9). Only two endpoints: the repository and its contents.
 */
interface GithubClient
{
    /**
     * `GET /repos/{owner}/{name}`: default branch, archived, the token's expiry.
     *
     * @throws CheckCouldNotRun "Token rejected (401)", "Repository not found or the token can't read it (404)", "GitHub rate limit reached", …
     */
    public function repository(string $owner, string $name, string $token): GithubRepository;

    /**
     * Files in the root of the default branch (`GET /repos/{owner}/{name}/contents`),
     * name => size in bytes. Needs the Contents permission.
     *
     * @return array<string, int>
     *
     * @throws CheckCouldNotRun
     */
    public function rootFiles(string $owner, string $name, string $token): array;

    /**
     * A file of the default branch, raw, at most `sextant.github.max_bytes`.
     *
     * @throws CheckCouldNotRun
     */
    public function file(string $owner, string $name, string $path, string $token): string;
}
