<?php

namespace App\Console\Commands;

use App\Checks\CheckerRegistry;
use App\Enums\Status;
use App\Models\Monitor;
use App\Monitoring\MonitorRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Runs one monitor now, in this process, and prints the result (SPEC §14). */
#[Signature('monitors:run {monitor : Monitor id} {--no-save : Print the result without storing it}')]
#[Description('Run one monitor synchronously and print the result')]
class RunMonitorCommand extends Command
{
    public function handle(MonitorRunner $runner, CheckerRegistry $registry): int
    {
        $monitor = Monitor::query()->find((int) $this->argument('monitor'));

        if ($monitor === null) {
            $this->components->error('No such monitor.');

            return self::FAILURE;
        }

        $outcome = $runner->run($monitor->id, save: ! $this->option('no-save'));

        if ($outcome === null) {
            $this->components->error('The monitor is disabled or its resource was deleted.');

            return self::FAILURE;
        }

        $resource = $monitor->resource()?->resourceName() ?? '?';
        $this->components->twoColumnDetail("{$resource} · {$registry->monitorName($monitor)}", strtoupper($outcome->result->status->value));
        $this->line("  {$outcome->result->message} ({$outcome->durationMs} ms)");

        if ($outcome->result->data !== [] && $this->output->isVerbose()) {
            $this->line((string) json_encode($outcome->result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $outcome->result->status === Status::Unknown ? self::FAILURE : self::SUCCESS;
    }
}
