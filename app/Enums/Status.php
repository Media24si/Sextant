<?php

namespace App\Enums;

/**
 * The four statuses of servers, domains, sites and monitors (GOAL "Status model").
 * Stored as varchar(10) (SPEC §3). rank() and isFailure() arrive with P1.2.
 */
enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Unknown = 'unknown';
}
