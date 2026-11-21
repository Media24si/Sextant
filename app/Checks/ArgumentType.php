<?php

namespace App\Checks;

/** Field types of the argument schema (SPEC §4.6). */
enum ArgumentType: string
{
    case Int = 'int';
    case String = 'string';
    case Bool = 'bool';
    case IntList = 'intList';
    case StringList = 'stringList';
    case Select = 'select';
}
