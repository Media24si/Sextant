<?php

namespace App\Versions;

use App\Models\Server;
use Illuminate\Container\Attributes\Scoped;

/**
 * The files a server needs for its versions (SPEC §11.3, §11.4, §11.8): the
 * collector, `versions.php` with the token's hash, and the installer that
 * writes both. The collector's `SEXTANT_COLLECTOR_VERSION` versions the kit.
 */
#[Scoped]
class CollectorKit
{
    private ?int $version = null;

    /** The collector script, the same for every server. */
    public function collectorScript(): string
    {
        return (string) file_get_contents(resource_path('collector/sextant-collect.sh'));
    }

    /** The current kit version, read from the collector script once per request. */
    public function version(): int
    {
        return $this->version ??= preg_match('/^SEXTANT_COLLECTOR_VERSION=(\d+)$/m', $this->collectorScript(), $match) === 1 ? (int) $match[1] : 1;
    }

    /**
     * Whether the server has read versions with an older collector. Collectors
     * before version 2 don't report a version.
     */
    public function isOutdated(Server $server, bool $hasReadings): bool
    {
        return $hasReadings && ($server->collector_version ?? 1) < $this->version();
    }

    /** `versions.php` for the server. Holds the token's SHA-256 hash, never the token. */
    public function endpointPhp(Server $server): string
    {
        return strtr((string) file_get_contents(resource_path('collector/versions.php.stub')), [
            '{{server}}' => $this->comment($server->name),
            '{{token_hash}}' => hash('sha256', (string) $server->versions_token),
            '{{file_path}}' => addslashes((string) config('sextant.versions.file_path')),
        ]);
    }

    /**
     * The installer for the server: the collector and `versions.php` inline
     * (base64), so one download sets up or updates everything.
     */
    public function installScript(Server $server): string
    {
        return strtr((string) file_get_contents(resource_path('collector/install.sh.stub')), [
            '{{server}}' => $this->comment($server->name),
            '{{collector_version}}' => (string) $this->version(),
            '{{collector}}' => base64_encode($this->collectorScript()),
            '{{endpoint}}' => base64_encode($this->endpointPhp($server)),
            '{{endpoint_file}}' => (string) config('sextant.versions.endpoint_file'),
            '{{location}}' => $this->location($server),
        ]);
    }

    /**
     * The URL path nginx serves `versions.php` at: the endpoint URL's path,
     * or the default when it's unset or has characters a shell would mind.
     */
    public function location(Server $server): string
    {
        $path = $server->versions_url === null ? null : parse_url($server->versions_url, PHP_URL_PATH);

        return is_string($path) && preg_match('~^/[A-Za-z0-9._/-]+$~', $path) === 1
            ? $path
            : (string) config('sextant.versions.default_path');
    }

    private function comment(string $text): string
    {
        return str_replace(["\n", "\r", '*/'], ' ', $text);
    }
}
