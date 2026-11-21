<?php

namespace App\Net\Real;

use App\Checks\CheckCouldNotRun;
use App\Net\VersionsClient;
use App\Versions\InvalidVersionsFile;
use App\Versions\VersionsFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class HttpVersionsClient implements VersionsClient
{
    public function fetch(string $url, string $token): VersionsFile
    {
        $maxBytes = (int) config('sextant.versions.max_bytes');
        $tooLarge = new CheckCouldNotRun('Invalid versions file: larger than '.intdiv($maxBytes, 1024).' KB');
        // Stops the download at the limit instead of checking after it.
        $sink = new CappedSink($maxBytes, abort: true);

        try {
            $response = Http::withUserAgent((string) config('sextant.user_agent'))
                ->withToken($token)
                ->accept('application/json')
                ->timeout((int) config('sextant.versions.timeout'))
                ->withoutRedirecting()
                ->withOptions(['sink' => $sink])
                ->get($url);
        } catch (Throwable $exception) {
            if ($sink->overflowed) {
                throw $tooLarge;
            }

            if (! $exception instanceof ConnectionException) {
                throw $exception;
            }

            throw new CheckCouldNotRun(str_contains(strtolower($exception->getMessage()), 'timed out')
                ? 'Endpoint timed out'
                : 'Endpoint unreachable: '.mb_strimwidth($exception->getMessage(), 0, 200, '…'));
        }

        $body = $response->body();

        match (true) {
            $response->status() === 401 => throw new CheckCouldNotRun('Token rejected (401)'),
            $response->status() === 403 && str_starts_with(strtolower($url), 'http://') => throw new CheckCouldNotRun('Plain HTTP refused (403): run the install command again so versions.php allows http://'),
            $response->status() === 503 => throw new CheckCouldNotRun('Endpoint says: '.($response->json('error') ?? 'unavailable')),
            ! $response->successful() => throw new CheckCouldNotRun("Endpoint returned HTTP {$response->status()}"),
            $sink->overflowed => throw $tooLarge,
            default => null,
        };

        try {
            return VersionsFile::fromJson($body);
        } catch (InvalidVersionsFile $exception) {
            throw new CheckCouldNotRun("Invalid versions file: {$exception->getMessage()}");
        }
    }
}
