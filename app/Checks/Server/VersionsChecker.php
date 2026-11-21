<?php

namespace App\Checks\Server;

use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\ServerChecker;
use App\Models\Server;
use App\Models\ServerSoftware;
use App\Net\VersionsClient;
use App\Support\Duration;
use App\Versions\MinimumVersions;
use App\Versions\VersionsRecorder;

/**
 * `server.versions`: reads the server's versions file, stores the readings
 * and compares them with the minimums (SPEC §9.9). Anything that keeps it
 * from reading a fresh file is Unknown, and the last stored versions stay.
 */
class VersionsChecker extends ServerChecker
{
    public function __construct(
        private VersionsClient $client,
        private VersionsRecorder $recorder,
        private MinimumVersions $minimums,
    ) {}

    public static function name(): string
    {
        return 'Software versions';
    }

    protected function check(Server $server, Arguments $arguments): CheckResult
    {
        if ($server->versions_url === null || $server->versions_token === null) {
            throw new CheckCouldNotRun('No versions endpoint configured');
        }

        $file = $this->client->fetch($server->versions_url, $server->versions_token);
        $staleHours = (int) config('sextant.versions.stale_hours');

        if ($file->generatedAt->lt(now()->subHours($staleHours))) {
            throw new CheckCouldNotRun('Collector last ran '.Duration::between($file->generatedAt, now()).' ago');
        }

        $this->recorder->record($server, $file, now());

        $rows = ServerSoftware::query()->where('server_id', $server->id)->get();
        $below = $this->minimums->below($rows);
        $data = [
            'read_at' => $file->generatedAt->toIso8601String(),
            'below' => $below,
            'counts' => ['readings' => $rows->count(), 'below' => count($below)],
        ];

        if ($below === []) {
            return CheckResult::ok(trans_choice('{1} 1 reading, at or above minimum|[2,*] :count readings, all at or above minimum', $rows->count()), $data);
        }

        return CheckResult::warning(
            implode('; ', array_map(fn (array $item): string => "{$item['software']} {$item['version']} < {$item['min_version']}", $below)),
            $data,
        );
    }
}
