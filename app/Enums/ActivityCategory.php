<?php

namespace App\Enums;

/** The three kinds of change-feed entries (SPEC §8.1). */
enum ActivityCategory: string
{
    case Status = 'status';
    case Inventory = 'inventory';
    case Detected = 'detected';
}
