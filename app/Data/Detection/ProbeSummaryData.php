<?php

namespace App\Data\Detection;

use App\Enums\Status;
use Spatie\LaravelData\Data;

/**
 * The first probe (SPEC §10.1 step 7).
 *
 * SPEC: the `*_status` fields aren't in §10.1; the page needs them for the
 * coloured dots in the prototype's "First probe" card.
 */
class ProbeSummaryData extends Data
{
    public function __construct(
        public string $http,
        public Status $http_status,
        public ?string $tls,
        public ?Status $tls_status,
        public string $redirect,
        public Status $redirect_status,
        public ?string $final_url,
    ) {}
}
