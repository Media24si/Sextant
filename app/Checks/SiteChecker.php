<?php

namespace App\Checks;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Base class for `site.*` checkers (SPEC §4.2). */
abstract class SiteChecker implements Checker
{
    use DescribesChanges;

    /** The registry key prefix these checkers must use. */
    public const string PREFIX = 'site';

    final public function run(Model $resource, Arguments $arguments): CheckResult
    {
        if (! $resource instanceof Site) {
            throw new InvalidArgumentException(static::class.' checks sites, not '.$resource::class);
        }

        return $this->check($resource, $arguments);
    }

    abstract protected function check(Site $site, Arguments $arguments): CheckResult;

    public static function arguments(): array
    {
        return [];
    }

    public static function trackedFields(): array
    {
        return [];
    }
}
