<?php

namespace App\Versions;

use App\Enums\Software;
use App\Models\MinimumVersion;
use App\Models\ServerSoftware;
use Illuminate\Support\Collection;

/**
 * Which readings are below the minimum an admin set (SPEC §11.6). A PHP
 * minimum applies to its branch only; the others have no branch.
 */
class MinimumVersions
{
    /** @var Collection<string, MinimumVersion>|null */
    private ?Collection $minimums = null;

    public function for(Software $software, string $branch): ?MinimumVersion
    {
        $this->minimums ??= MinimumVersion::query()->get()
            ->keyBy(fn (MinimumVersion $minimum): string => "{$minimum->software->value}:{$minimum->branch}");

        return $this->minimums->get("{$software->value}:".($software === Software::Php ? $branch : ''));
    }

    public function isBelow(ServerSoftware $row): bool
    {
        $minimum = $this->for($row->software, $row->branch);

        return $minimum !== null && VersionComparator::isBelow($row->software, $row->version, $minimum->min_version);
    }

    /**
     * @param  iterable<ServerSoftware>  $rows
     * @return list<array{software: string, branch: string, version: string, min_version: string, cve_url: string|null}>
     */
    public function below(iterable $rows): array
    {
        $below = [];

        foreach ($rows as $row) {
            $minimum = $this->for($row->software, $row->branch);

            if ($minimum !== null && VersionComparator::isBelow($row->software, $row->version, $minimum->min_version)) {
                $below[] = [
                    'software' => $row->label(),
                    'branch' => $row->branch,
                    'version' => $row->version,
                    'min_version' => $minimum->min_version,
                    'cve_url' => $minimum->cve_url,
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
