<?php

namespace App\Checks\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * An `only_if` condition on a default monitor (SPEC §4.4): false skips it.
 */
interface Condition
{
    /**
     * @param  array<string, mixed>  $arguments  the default entry's arguments
     */
    public function __invoke(Model $resource, array $arguments): bool;
}
