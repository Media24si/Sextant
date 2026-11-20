<?php

namespace App\Data;

use App\Enums\SearchResultType;
use App\Enums\Status;
use Spatie\LaravelData\Data;

/** One global search result (SPEC §13.8). */
class SearchResultData extends Data
{
    public function __construct(
        public SearchResultType $type,
        public int $id,
        public string $label,
        public string $context,
        public ?Status $status,
        public string $url,
    ) {}
}
