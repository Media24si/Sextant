<?php

namespace App\Enums;

/** What happened to one URL in a bulk import (SPEC §10.6). */
enum ImportOutcome: string
{
    case Created = 'created';
    case NeedsReview = 'needs_review';
    case Skipped = 'skipped';
}
