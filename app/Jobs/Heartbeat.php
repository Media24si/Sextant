<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;

use function Sentry\captureCheckIn;

/**
 * Proves a queue's workers are alive: runs on that queue and checks in to
 * Sentry Crons as `sextant-queue-{queue}` (SPEC §12.2). A missed check-in
 * means the queue is stuck.
 */
class Heartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function handle(): void
    {
        captureCheckIn(
            slug: "sextant-queue-{$this->queueName}",
            status: CheckInStatus::ok(),
            monitorConfig: new MonitorConfig(
                MonitorSchedule::crontab('*/5 * * * *'),
                checkinMargin: 2,
                maxRuntime: 5,
                timezone: 'UTC',
            ),
        );
    }
}
