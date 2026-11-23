<?php

namespace App\Packages;

use App\Models\Repository;
use App\Models\RepositoryPackage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Stores a repository's packages from one read (SPEC §11.9): the previous
 * rows are replaced, in one transaction. No change feed entries: a single
 * `composer update` changes dozens of packages.
 */
class PackagesRecorder
{
    private const CHUNK = 500;

    /**
     * @param  list<PackageReading>  $readings
     * @param  list<string>  $lockFiles  the lock files read
     */
    public function record(Repository $repository, array $readings, array $lockFiles, string $defaultBranch, CarbonInterface $now): void
    {
        DB::transaction(function () use ($repository, $readings, $lockFiles, $defaultBranch, $now): void {
            RepositoryPackage::query()->where('repository_id', $repository->id)->delete();

            $rows = array_map(fn (PackageReading $reading): array => [
                'repository_id' => $repository->id,
                'ecosystem' => $reading->ecosystem->value,
                'name' => $reading->name,
                'version' => $reading->version,
                'dev' => $reading->dev,
                'direct' => $reading->direct,
            ], PackageReading::merge($readings));

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                RepositoryPackage::query()->insert($chunk);
            }

            $repository->forceFill([
                'default_branch' => $defaultBranch,
                'lock_files' => $lockFiles,
                'read_at' => $now,
            ])->saveQuietly();
        });
    }
}
