<?php

namespace App\Versions;

use Closure;

/**
 * Which listed minimum a version is compared with (SPEC §11.6): the one on
 * the nearest listed branch at or below the version's branch, or the lowest
 * one when the version's branch is older than every listed branch (so it's
 * below). Shared by server software and packages, which differ only in what
 * a branch is.
 */
final class Floor
{
    /**
     * @param  list<string>  $versions  the listed minimums
     * @param  Closure(string): ?string  $branchOf  e.g. `8.3` for `8.3.14`, `12` for `v12.9.1`
     * @param  Closure(string, string): int  $compare
     */
    public static function pick(array $versions, string $version, Closure $branchOf, Closure $compare): ?string
    {
        usort($versions, $compare);

        if ($versions === []) {
            return null;
        }

        $branch = $branchOf($version);
        $floor = $versions[0];

        if ($branch === null) {
            return $floor;
        }

        foreach ($versions as $candidate) {
            $candidateBranch = $branchOf($candidate);

            if ($candidateBranch !== null && version_compare($candidateBranch, $branch, '<=')) {
                $floor = $candidate;
            }
        }

        return $floor;
    }
}
