<?php

namespace App\Checks;

use Illuminate\Database\Eloquent\Model;

/**
 * A class that checks one kind of thing (SPEC §4.1, §4.2). Registered under a
 * key such as `site.http` in `config/checkers.php`.
 */
interface Checker
{
    /** Human name, e.g. "HTTP". */
    public static function name(): string;

    /**
     * Schema for the monitor form and validation (§4.6).
     *
     * @return list<Argument>
     */
    public static function arguments(): array;

    /**
     * Data keys worth recording when they change (§8.3).
     *
     * @return array<string, string> data key => activity type
     */
    public static function trackedFields(): array;

    public static function describeChange(string $field, mixed $old, mixed $new): string;

    /**
     * @throws CheckCouldNotRun when the check couldn't run for reasons on our side
     */
    public function run(Model $resource, Arguments $arguments): CheckResult;
}
