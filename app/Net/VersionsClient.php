<?php

namespace App\Net;

use App\Checks\CheckCouldNotRun;
use App\Versions\VersionsFile;

/** Fetches a server's versions file from its endpoint (SPEC §11.4). */
interface VersionsClient
{
    /**
     * Bearer token, TLS verified, 10-second timeout, at most 256 KB.
     *
     * @throws CheckCouldNotRun with a precise message: "Token rejected (401)", "Endpoint timed out", "Invalid versions file: …"
     */
    public function fetch(string $url, string $token): VersionsFile;
}
