<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Short durations for messages: "45 s", "15 min", "2 h 5 min", "3 d 4 h". */
class Duration
{
    public static function between(CarbonInterface $from, CarbonInterface $to): string
    {
        $seconds = (int) abs($to->getTimestamp() - $from->getTimestamp());

        if ($seconds < 60) {
            return "{$seconds} s";
        }

        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $days = intdiv($hours, 24);

        return match (true) {
            $days > 0 => $days.' d'.($hours % 24 > 0 ? ' '.($hours % 24).' h' : ''),
            $hours > 0 => $hours.' h'.($minutes % 60 > 0 ? ' '.($minutes % 60).' min' : ''),
            default => "{$minutes} min",
        };
    }
}
