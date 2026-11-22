<?php

namespace App\Versions;

use App\Enums\Software;
use App\Models\MinimumVersion;
use App\Models\ServerSoftware;
use Illuminate\Support\Collection;

/**
 * Which readings are below the minimum an admin set (SPEC §11.6). Each
 * software has one minimum with a version per branch; a reading is compared
 * with the version of the nearest listed branch at or below its own.
 */
class MinimumVersions
{
    /** @var Collection<string, MinimumVersion>|null */
    private ?Collection $minimums = null;

    public function for(Software $software): ?MinimumVersion
    {
        $this->minimums ??= MinimumVersion::query()->get()
            ->keyBy(fn (MinimumVersion $minimum): string => $minimum->software->value);

        return $this->minimums->get($software->value);
    }

    /** The listed version this reading is compared with, or null without a minimum. */
    public function floorFor(ServerSoftware $row): ?string
    {
        return $this->for($row->software)?->floorFor($row->version);
    }

    public function isBelow(ServerSoftware $row): bool
    {
        $floor = $this->floorFor($row);

        return $floor !== null && VersionComparator::isBelow($row->software, $row->version, $floor);
    }

    /**
     * @param  iterable<ServerSoftware>  $rows
     * @return list<array{software: string, branch: string, version: string, min_version: string, cve_url: string|null}>
     */
    public function below(iterable $rows): array
    {
        $below = [];

        foreach ($rows as $row) {
            $floor = $this->floorFor($row);

            if ($floor !== null && VersionComparator::isBelow($row->software, $row->version, $floor)) {
                $below[] = [
                    'software' => $row->label(),
                    'branch' => $row->branch,
                    'version' => $row->version,
                    'min_version' => $floor,
                    'cve_url' => $this->for($row->software)?->cve_url,
                ];
            }
        }

        return $below;
    }

    /**
     * Server ids with anything below a minimum, for the dashboard and the
     * Servers filter.
     *
     * @return list<int>
     */
    public function serverIdsBelow(): array
    {
        return array_values(ServerSoftware::query()
            ->whereHas('server')
            ->get()
            ->filter(fn (ServerSoftware $row): bool => $this->isBelow($row))
            ->pluck('server_id')
            ->unique()
            ->values()
            ->all());
    }

    /** Forget cached minimums, e.g. after one changed. */
    public function flush(): void
    {
        $this->minimums = null;
    }
}
