<?php

namespace App\Enums;

/** Where a package comes from (SPEC §11.9). */
enum Ecosystem: string
{
    case Composer = 'composer';
    case Npm = 'npm';

    public function label(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
        };
    }
}
