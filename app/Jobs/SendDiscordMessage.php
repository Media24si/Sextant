<?php

namespace App\Jobs;

use App\Models\IncidentEvent;
use App\Net\WebClient;
use App\Notifications\Discord\NotificationFlusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use RuntimeException;
use Throwable;

/**
 * Posts one message to the Discord webhook (SPEC §12.1). Runs on the
 * single-worker `notifications` queue, limited to 20 per minute. A 429 is
 * honoured by waiting `retry_after`; after 5 failed sends the job fails and
 * Sentry hears about it. Its events then go back to the outbox, so the next
 * flush sends them again; a message Discord refuses (4xx) is marked rejected.
 */
class SendDiscordMessage implements ShouldQueue
{
    use Queueable;

    /** Failures (not rate-limit waits) before giving up. */
    public int $maxExceptions = 5;

    public int $timeout = 30;

    /**
     * @param  array{embeds: list<array<string, mixed>>, allowed_mentions: array{parse: list<string>}}  $payload
     * @param  list<int>  $eventIds  the incident events this message reports
     */
    public function __construct(public array $payload, public array $eventIds = []) {}

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

        $response = app(WebClient::class)->postJson(
            $webhook.(str_contains($webhook, '?') ? '&' : '?').'wait=true',
            $this->payload,
            timeout: 15,
        );

        if ($response->status() === 429) {
            $retryAfter = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 5);
            $this->release((int) ceil($retryAfter));

            return;
        }

        if ($response->failed()) {
            // Never include the webhook URL: it's a secret (docs/CLAUDE.md).
            $exception = new RuntimeException("Discord answered HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 300, '…'), $response->status());

            if ($response->clientError()) {
                // The same message would be refused again: fail now.
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    /**
     * Out of attempts, out of time (the worker was down for over an hour),
     * or refused: never leave the events marked as sent.
     */
    public function failed(?Throwable $exception): void
    {
        $code = $exception?->getCode();
        $rejected = is_int($code) && $code >= 400 && $code < 500;

        IncidentEvent::query()
            ->whereKey($this->eventIds)
            ->update($rejected
                ? ['suppressed_reason' => NotificationFlusher::SUPPRESSED_REJECTED]
                : ['notified_at' => null, 'suppressed_reason' => null]);
    }
}
