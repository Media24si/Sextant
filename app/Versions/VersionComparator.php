<?php

namespace App\Versions;

use App\Enums\Software;

/**
 * Version normalisation and comparison (SPEC §11.6). Only upstream versions
 * are compared; package versions are shown beside them so backports can be
 * spotted.
 */
class VersionComparator
{
    /**
     * Kernel: drop the flavour and turn `-` into `.` (`6.8.0-49-generic` →
     * `6.8.0.49`). Others: the leading `\d+(\.\d+)*` (`8.0.40-0ubuntu…` → `8.0.40`).
     */
    public static function normalise(Software $software, string $version): ?string
    {
        $version = trim($version);

        if ($software === Software::Kernel) {
            if (preg_match('/^(\d+(?:\.\d+)*)(?:-(\d+))?/', $version, $match) !== 1) {
                return null;
            }

            return $match[1].(isset($match[2]) ? ".{$match[2]}" : '');
        }

        return preg_match('/^\d+(?:\.\d+)*/', $version, $match) === 1 ? $match[0] : null;
    }

    /** The first two parts of the normalised version: `8.2`, `1.30`, a kernel's `6.8`. */
    public static function branch(Software $software, string $version): ?string
    {
        $normalised = self::normalise($software, $version);

        return $normalised === null ? null : implode('.', array_slice(explode('.', $normalised), 0, 2));
    }

    /**
     * Below the minimum? Unparseable versions are never below (they can't be
     * judged; the admin sees them on the server page).
     */
    public static function isBelow(Software $software, string $version, string $minimum): bool
    {
        $normalised = self::normalise($software, $version);
        $min = self::normalise($software, $minimum) ?? $minimum;

        return $normalised !== null && version_compare($normalised, $min, '<');
    }

    /** -1, 0 or 1, for upgraded / downgraded (§11.5). */
    public static function compare(Software $software, string $a, string $b): int
    {
        return version_compare(self::normalise($software, $a) ?? $a, self::normalise($software, $b) ?? $b);
    }
}
