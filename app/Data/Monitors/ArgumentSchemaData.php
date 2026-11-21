<?php

namespace App\Data\Monitors;

use Spatie\LaravelData\Data;

/** One field of a checker's argument schema, for `MonitorArgumentsForm` (SPEC §4.6). */
class ArgumentSchemaData extends Data
{
    public function __construct(
        /** int, string, bool, intList, stringList or select */
        public string $type,
        public string $name,
        public string $label,
        public ?string $help,
        public bool $nullable,
        /** @var array<string, string>|null value => label, for select */
        public ?array $options,
        /** The registry default, shown as the placeholder (inherited value). */
        public mixed $default,
    ) {}
}
