<?php

namespace App\Checks;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Base class for `server.*` checkers (SPEC §4.2). */
abstract class ServerChecker implements Checker
{
    use DescribesChanges;

    /** The registry key prefix these checkers must use. */
    public const string PREFIX = 'server';

    final public function run(Model $resource, Arguments $arguments): CheckResult
    {
        if (! $resource instanceof Server) {
            throw new InvalidArgumentException(static::class.' checks servers, not '.$resource::class);
        }

        return $this->check($resource, $arguments);
    }

    abstract protected function check(Server $server, Arguments $arguments): CheckResult;

    public static function arguments(): array
    {
        return [];
    }

    public static function trackedFields(): array
    {
        return [];
    }
}
