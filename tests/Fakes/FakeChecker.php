<?php

namespace Tests\Fakes;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckResult;
use App\Checks\SiteChecker;
use App\Models\Site;
use Closure;

/**
 * A checker for engine tests, registered as `site.fake` (SPEC §17). Each run
 * takes the next queued result; a closure can throw instead.
 */
class FakeChecker extends SiteChecker
{
    /** @var list<CheckResult|Closure(): CheckResult> */
    public static array $results = [];

    public static int $runs = 0;

    /**
     * Register under `site.fake` with these registry settings.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function register(array $entry = []): void
    {
        self::$results = [];
        self::$runs = 0;

        // Keys contain dots, so the whole list is set at once.
        config(['checkers.checkers' => [...config('checkers.checkers'), 'site.fake' => [
            'class' => self::class,
            'queue' => 'checks',
            'interval' => 5,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['label' => 'default'],
            ...$entry,
        ]]]);
    }

    public static function then(CheckResult|Closure ...$results): void
    {
        array_push(self::$results, ...$results);
    }

    public static function name(): string
    {
        return 'Fake';
    }

    public static function arguments(): array
    {
        return [Argument::string('label', 'Label')];
    }

    public static function trackedFields(): array
    {
        return ['version' => 'software.upgraded'];
    }

    protected function check(Site $site, Arguments $arguments): CheckResult
    {
        self::$runs++;
        $next = array_shift(self::$results) ?? CheckResult::ok('Fine');

        return $next instanceof Closure ? $next() : $next;
    }
}
