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
