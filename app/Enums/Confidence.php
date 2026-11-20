<?php

namespace App\Enums;

/**
 * How sure site detection is about a finding (SPEC §10.1, §10.2).
 * "Chosen by you" is a UI state, not a stored confidence.
 */
enum Confidence: string
{
    case Detected = 'detected';
    case Suggested = 'suggested';
    case NotFound = 'not_found';
}
