<?php

namespace App\Console\Commands;

use App\Monitoring\MonitorClaimer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Every minute: claim due monitors and queue their runs (SPEC §5.1). */
#[Signature('monitors:dispatch')]
#[Description('Queue runs for monitors that are due')]
class DispatchMonitors extends Command
{
    /** Stop claiming new batches after this long, so runs never overlap. */
    private const DEADLINE_SECONDS = 50;

    public function handle(MonitorClaimer $claimer): int
    {
        $queued = $claimer->dispatchDue(now()->addSeconds(self::DEADLINE_SECONDS));

        $this->components->info("{$queued} runs queued.");

        return self::SUCCESS;
    }
}
