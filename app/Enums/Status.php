<?php

namespace App\Enums;

/**
 * The four statuses of servers, domains, sites and monitors (GOAL "Status
 * model", SPEC §4.2). Stored as varchar(10) (SPEC §3).
 */
enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Unknown = 'unknown';

    /** Ordering used when combining statuses: error > warning > unknown > ok. */
    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Unknown => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Warning || $this === self::Error;
    }

    /**
     * The worst of the given statuses; Unknown when there are none.
     */
    public static function worst(self ...$statuses): self
    {
        $worst = null;

        foreach ($statuses as $status) {
            if ($worst === null || $status->rank() > $worst->rank()) {
                $worst = $status;
            }
        }

        return $worst ?? self::Unknown;
    }
}
