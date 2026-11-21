<?php

namespace App\Jobs;

use App\Monitoring\MonitorRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One monitor run (SPEC §5.2). No retries: the next scheduled run is the
 * retry.
 */
class RunMonitor implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $monitorId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("monitor:{$this->monitorId}"))->dontRelease()->expireAfter(120)];
    }

    public function handle(MonitorRunner $runner): void
    {
        $runner->run($this->monitorId);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ["monitor:{$this->monitorId}"];
    }
}
