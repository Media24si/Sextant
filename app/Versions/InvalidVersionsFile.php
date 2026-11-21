<?php

namespace App\Versions;

use RuntimeException;

/** A versions file that doesn't follow schema 1 (SPEC §11.3). */
final class InvalidVersionsFile extends RuntimeException {}
