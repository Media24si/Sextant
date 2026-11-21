<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Domain;
use App\Models\MonitorState;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\EffectiveStatus;
use App\Monitoring\ResourceStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every minute: monitors whose last result went stale become Unknown, and
 * every resource's status is refreshed (SPEC §6.1, §7, §14). Reminders join
 * in Phase 4.
 */
#[Signature('sextant:sweep')]
#[Description('Mark stale monitors Unknown and refresh resource statuses')]
class Sweep extends Command
{
    public function handle(): int
    {
        $now = now();
        $stale = 0;

        MonitorState::query()
            ->where('status', '!=', Status::Unknown)
            ->where('stale_at', '<', $now)
            ->eachById(function (MonitorState $state) use ($now, &$stale): void {
                $status = EffectiveStatus::of($state, $now);

                if ($status !== $state->status) {
                    $state->forceFill(['status' => $status, 'status_changed_at' => $now])->save();
                    $stale++;
                }
            }, column: 'monitor_id');

        foreach ([Server::class, Domain::class, Site::class] as $model) {
            $model::query()->eachById(fn (Server|Domain|Site $resource): mixed => ResourceStatus::refresh($resource));
        }

        $this->components->info("{$stale} monitors went stale.");

        return self::SUCCESS;
    }
}
