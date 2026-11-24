<?php

namespace App\Packages;

/**
 * Package version normalisation and comparison (SPEC §11.9). A package's
 * branch is its major version: `v12.9.1` is on `12`. Pre-release and build
 * suffixes are dropped (`4.0.0-beta.1` → `4.0.0`). Branch versions such as
 * `dev-main` can't be judged and are never below.
 */
final class PackageVersion
{
    public static function normalise(string $version): ?string
    {
        return preg_match('/^\d+(?:\.\d+)*/', ltrim(trim($version), 'vV'), $match) === 1 ? $match[0] : null;
    }

    /** The major version: `12` for `v12.9.1`. */
    public static function branch(string $version): ?string
    {
        $normalised = self::normalise($version);

        return $normalised === null ? null : explode('.', $normalised)[0];
    }

    /**
     * The release line a listed minimum is for: every part but the last, so
     * `8.3.7` is for `8.3` and `12.13` for all of `12` (a lone `3` is `3`).
     */
    public static function line(string $version): ?string
    {
        $normalised = self::normalise($version);

        if ($normalised === null) {
            return null;
        }

        $parts = explode('.', $normalised);

        return implode('.', array_slice($parts, 0, max(1, count($parts) - 1)));
    }

    /** Whether a version is on the given line or a newer one: `8.4.0` and `8.3.9` are at or above `8.3`. */
    public static function isAtOrAboveLine(string $version, string $line): bool
    {
        $normalised = self::normalise($version);

        if ($normalised === null) {
            return false;
        }

        $truncated = implode('.', array_slice(explode('.', $normalised), 0, count(explode('.', $line))));

        return version_compare($truncated, $line, '>=');
    }

    public static function isBelow(string $version, string $minimum): bool
    {
        $normalised = self::normalise($version);

        return $normalised !== null && version_compare($normalised, self::normalise($minimum) ?? $minimum, '<');
    }

    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalise($a) ?? $a, self::normalise($b) ?? $b);
    }
}
