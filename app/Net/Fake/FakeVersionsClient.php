<?php

namespace App\Net\Fake;

use App\Checks\CheckCouldNotRun;
use App\Net\VersionsClient;
use App\Versions\InvalidVersionsFile;
use App\Versions\VersionsFile;

/** Canned versions files for tests, by URL. Unknown URLs time out. */
class FakeVersionsClient implements VersionsClient
{
    /** @var array<string, array<mixed>|CheckCouldNotRun> */
    private array $responses = [];

    /** @var list<array{url: string, token: string}> */
    public array $fetched = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(VersionsClient::class, $fake);

        return $fake;
    }

    /**
     * @param  array<mixed>  $file  a versions.json as an array
     */
    public function respond(string $url, array $file): self
    {
        $this->responses[$url] = $file;

        return $this;
    }

    public function fail(string $url, string $message): self
    {
        $this->responses[$url] = new CheckCouldNotRun($message);

        return $this;
    }

    public function fetch(string $url, string $token): VersionsFile
    {
        $this->fetched[] = ['url' => $url, 'token' => $token];
        $response = $this->responses[$url] ?? new CheckCouldNotRun('Endpoint timed out');

        if ($response instanceof CheckCouldNotRun) {
            throw $response;
        }

        try {
            return VersionsFile::fromArray($response);
        } catch (InvalidVersionsFile $exception) {
            throw new CheckCouldNotRun("Invalid versions file: {$exception->getMessage()}");
        }
    }
}
