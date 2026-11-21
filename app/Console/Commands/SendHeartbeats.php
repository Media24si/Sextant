<?php

namespace App\Console\Commands;

use App\Jobs\Heartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Every 5 minutes: a heartbeat job on each queue (SPEC §12.2). */
#[Signature('sextant:heartbeat')]
#[Description('Queue Sentry Crons heartbeats on the check and notification queues')]
class SendHeartbeats extends Command
{
    /** The queues whose workers Sentry watches. */
    public const QUEUES = ['checks', 'checks-slow', 'notifications'];

    public function handle(): int
    {
        foreach (self::QUEUES as $queue) {
            Heartbeat::dispatch($queue);
        }

        $this->components->info('Heartbeats queued on '.implode(', ', self::QUEUES).'.');

        return self::SUCCESS;
    }
}
