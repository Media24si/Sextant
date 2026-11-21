<?php

namespace App\Versions;

use App\Activity\ActivityRecorder;
use App\Enums\ActivityCategory;
use App\Enums\Software;
use App\Models\Server;
use App\Models\ServerSoftware;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Stores a server's readings (SPEC §11.5): one row per software and PHP
 * branch, removals deleted, `os_reported` written quietly, and a `detected`
 * entry per change. The first reading records nothing in the feed.
 */
class VersionsRecorder
{
    public function record(Server $server, VersionsFile $file, CarbonInterface $fetchedAt): void
    {
        DB::transaction(function () use ($server, $file, $fetchedAt): void {
            $existing = ServerSoftware::query()->where('server_id', $server->id)->get()
                ->keyBy(fn (ServerSoftware $row): string => "{$row->software->value}:{$row->branch}");
            $firstReading = $existing->isEmpty();
            $seen = [];

            foreach ($file->readings as $reading) {
                $seen[$reading->key()] = true;
                $row = $existing->get($reading->key());

                if (! $firstReading) {
                    $this->recordChange($server, $reading, $row);
                }

                ServerSoftware::query()->updateOrCreate(
                    ['server_id' => $server->id, 'software' => $reading->software, 'branch' => $reading->branch],
                    [
                        'version' => mb_strimwidth($reading->version, 0, 50),
                        'package_version' => $reading->package,
                        'details' => $reading->details,
                        'read_at' => $file->generatedAt,
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }

            foreach ($existing as $key => $row) {
                if (isset($seen[$key])) {
                    continue;
                }

                $row->delete();

                if (! $firstReading) {
                    $this->feed($server, 'software.removed', "{$server->name}: {$row->label()} {$row->version} removed", $row->label(), $row->version, null);
                }
            }

            if ($server->os_reported !== $file->osPrettyName) {
                $server->forceFill(['os_reported' => $file->osPrettyName])->saveQuietly();
            }
        });
    }

    private function recordChange(Server $server, SoftwareReading $reading, ?ServerSoftware $row): void
    {
        $label = $reading->software->labelWithBranch($reading->branch);

        if ($row === null) {
            $this->feed($server, 'software.installed', "{$server->name}: {$label} {$reading->version} installed", $label, null, $reading->version);

            return;
        }

        if ($row->version === $reading->version) {
            return;
        }

        if ($reading->software === Software::Os) {
            $old = $row->details['pretty_name'] ?? $row->version;
            $new = $reading->details['pretty_name'] ?? $reading->version;
            $this->feed($server, 'server.os_changed', "{$server->name}: OS {$old} → {$new}", $label, (string) $old, (string) $new);

            return;
        }

        $type = VersionComparator::compare($reading->software, $reading->version, $row->version) >= 0 ? 'software.upgraded' : 'software.downgraded';
        $this->feed($server, $type, "{$server->name}: {$label} {$row->version} → {$reading->version}", $label, $row->version, $reading->version);
    }

    private function feed(Server $server, string $type, string $summary, string $software, ?string $old, ?string $new): void
    {
        ActivityRecorder::record(
            ActivityCategory::Detected,
            $type,
            $server,
            $summary,
            ['software' => $software, 'old' => $old, 'new' => $new],
            userId: null,
        );
    }
}
