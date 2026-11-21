<?php

namespace App\Enums;

/** What the versions collector reports (SPEC §3.14, §11.3). */
enum Software: string
{
    case Os = 'os';
    case Kernel = 'kernel';
    case Nginx = 'nginx';
    case Php = 'php';
    case Mysql = 'mysql';
    case Openssl = 'openssl';

    public function label(): string
    {
        return match ($this) {
            self::Os => 'Operating system',
            self::Kernel => 'Kernel',
            self::Nginx => 'nginx',
            self::Php => 'PHP',
            self::Mysql => 'MySQL',
            self::Openssl => 'OpenSSL',
        };
    }

    /** "PHP 8.2", "nginx". */
    public function labelWithBranch(string $branch): string
    {
        return $branch === '' ? $this->label() : "{$this->label()} {$branch}";
    }

    /** Minimums can be set for everything but the OS (§3.15). */
    public function hasMinimum(): bool
    {
        return $this !== self::Os;
    }
}
