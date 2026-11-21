<?php

namespace App\Enums;

/** Why an incident was resolved (SPEC §3.11). There is no manual resolve. */
enum IncidentResolution: string
{
    case Recovered = 'recovered';
    case MonitorDisabled = 'monitor_disabled';
    case MonitorDeleted = 'monitor_deleted';
    case ResourceDeleted = 'resource_deleted';

    public function label(): string
    {
        return match ($this) {
            self::Recovered => 'recovered',
            self::MonitorDisabled => 'monitor disabled',
            self::MonitorDeleted => 'monitor deleted',
            self::ResourceDeleted => 'resource deleted',
        };
    }
}
