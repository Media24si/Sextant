<?php

namespace App\Monitoring;

use App\Checks\CheckResult;

/** One finished run, for `monitors:run` and tests. */
final readonly class RunOutcome
{
    public function __construct(
        public CheckResult $result,
        public int $durationMs,
        public ?StateTransition $transition = null,
    ) {}
}
