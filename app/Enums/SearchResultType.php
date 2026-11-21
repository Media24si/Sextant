<?php

namespace App\Enums;

/** The kinds of global search results, in display order (SPEC §13.8). */
enum SearchResultType: string
{
    case Server = 'server';
    case ExternalService = 'external_service';
    case Domain = 'domain';
    case Site = 'site';
    case Application = 'application';
}
