<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts one message to the Discord webhook (SPEC §12.1). Runs on the
 * single-worker `notifications` queue, limited to 20 per minute. A 429 is
 * honoured by waiting `retry_after`; after 5 failed sends the job fails and
 * Sentry hears about it.
 */
class SendDiscordMessage implements ShouldQueue
{
    use Queueable;

    /** Failures (not rate-limit waits) before giving up. */
    public int $maxExceptions = 5;

    public int $timeout = 30;

    /**
     * @param  array{content: string|null, embeds: list<array<string, mixed>>, allowed_mentions: array{parse: list<string>}}  $payload
     */
    public function __construct(public array $payload) {}

    /** Rate-limit waits and 429s release the job; keep trying for an hour. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('discord')];
    }

    public function handle(): void
    {
        $webhook = (string) config('sextant.notifications.discord_webhook_url');

        if ($webhook === '') {
            return;
        }

        $response = Http::timeout(15)
            ->withUserAgent((string) config('sextant.user_agent'))
            ->post($webhook.(str_contains($webhook, '?') ? '&' : '?').'wait=true', array_filter($this->payload, fn (mixed $value): bool => $value !== null));

        if ($response->status() === 429) {
            $retryAfter = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 5);
            $this->release((int) ceil($retryAfter));

            return;
        }

        if ($response->failed()) {
            // Never include the webhook URL: it's a secret (docs/CLAUDE.md).
            throw new RuntimeException("Discord answered HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 300, '…'));
        }
    }
}
