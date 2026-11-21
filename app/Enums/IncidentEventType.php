<?php

namespace App\Enums;

/** Entries on an incident's timeline (SPEC §3.12, §6.3). */
enum IncidentEventType: string
{
    case Opened = 'opened';
    case Escalated = 'escalated';
    case Deescalated = 'deescalated';
    case Acknowledged = 'acknowledged';
    case Reminder = 'reminder';
    case Resolved = 'resolved';
}
