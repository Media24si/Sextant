<?php

namespace App\Net;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Plain HTTP that isn't a check: list downloads (CDN ranges, the Public
 * Suffix List) and the Discord webhook. Built on Laravel's HTTP client, so
 * its fake is `Http::fake()`, and `Http::preventStrayRequests()` in the test
 * suite stops anything unfaked. Checks use HttpProbe instead.
 */
class WebClient
{
    public function get(string $url, int $timeout): Response
    {
        return Http::withUserAgent((string) config('sextant.user_agent'))->timeout($timeout)->get($url);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function postJson(string $url, array $payload, int $timeout): Response
    {
        return Http::withUserAgent((string) config('sextant.user_agent'))->timeout($timeout)->post($url, $payload);
    }
}
