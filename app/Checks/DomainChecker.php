<?php

namespace App\Checks;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Base class for `domain.*` checkers (SPEC §4.2). */
abstract class DomainChecker implements Checker
{
    use DescribesChanges;

    /** The registry key prefix these checkers must use. */
    public const string PREFIX = 'domain';

    final public function run(Model $resource, Arguments $arguments): CheckResult
    {
        if (! $resource instanceof Domain) {
            throw new InvalidArgumentException(static::class.' checks domains, not '.$resource::class);
        }

        return $this->check($resource, $arguments);
    }

    abstract protected function check(Domain $domain, Arguments $arguments): CheckResult;

    public static function arguments(): array
    {
        return [];
    }

    public static function trackedFields(): array
    {
        return [];
    }
}
