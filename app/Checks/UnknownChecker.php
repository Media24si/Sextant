<?php

namespace App\Checks;

use RuntimeException;

/** A monitor names a registry key that isn't in `config/checkers.php` (§5.3). */
final class UnknownChecker extends RuntimeException
{
    public static function key(string $key): self
    {
        return new self("Checker `{$key}` is not registered");
    }
}
