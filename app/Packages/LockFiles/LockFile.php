<?php

namespace App\Packages\LockFiles;

use App\Packages\PackageReading;

/**
 * A lock file format (SPEC §11.9). Versions are kept as locked, e.g.
 * `v13.17.0` or `dev-main`; comparison normalises them.
 */
interface LockFile
{
    /** The manifest beside the lock file that names the direct dependencies, if this format needs one. */
    public function manifest(): ?string;

    /**
     * @param  string|null  $manifest  the manifest's contents when it exists
     * @return list<PackageReading>
     *
     * @throws InvalidLockFile
     */
    public function parse(string $contents, ?string $manifest = null): array;
}
