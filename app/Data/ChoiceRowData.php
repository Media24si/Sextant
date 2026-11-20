<?php

namespace App\Data;

use App\Models\Choice;
use Spatie\LaravelData\Data;

/**
 * An entry on the Lists page (SPEC §13.6). Expects `os_servers_count` and
 * `backup_servers_count`.
 */
class ChoiceRowData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public int $position,
        public int $servers_count,
    ) {}

    public static function fromModel(Choice $choice): self
    {
        return new self(
            id: $choice->id,
            label: $choice->label,
            position: $choice->position,
            servers_count: (int) $choice->getAttribute('os_servers_count') + (int) $choice->getAttribute('backup_servers_count'),
        );
    }
}
