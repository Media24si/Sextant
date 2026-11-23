<?php

namespace App\Checks;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Base class for `repository.*` checkers (SPEC §4.2). */
abstract class RepositoryChecker implements Checker
{
    use DescribesChanges;

    /** The registry key prefix these checkers must use. */
    public const string PREFIX = 'repository';

    final public function run(Model $resource, Arguments $arguments): CheckResult
    {
        if (! $resource instanceof Repository) {
            throw new InvalidArgumentException(static::class.' checks repositories, not '.$resource::class);
        }

        return $this->check($resource, $arguments);
    }

    abstract protected function check(Repository $repository, Arguments $arguments): CheckResult;

    public static function arguments(): array
    {
        return [];
    }

    public static function trackedFields(): array
    {
        return [];
    }
}
