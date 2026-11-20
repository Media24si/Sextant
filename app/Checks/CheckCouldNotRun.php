<?php

namespace App\Checks;

use RuntimeException;

/**
 * Thrown when a check or probe couldn't run for reasons on Sextant's side
 * (resolver timeout, rate limit, our own connectivity). The result becomes
 * Unknown, never Error (GOAL "Unknown is not Error", SPEC §4.2).
 */
final class CheckCouldNotRun extends RuntimeException {}
