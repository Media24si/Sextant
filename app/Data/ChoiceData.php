<?php

namespace App\Data;

use App\Models\Choice;
use Spatie\LaravelData\Data;

/** An entry of an admin-editable list, for selects and checkboxes. */
class ChoiceData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
    ) {}

    public static function fromModel(Choice $choice): self
    {
        return new self($choice->id, $choice->label);
    }
}
