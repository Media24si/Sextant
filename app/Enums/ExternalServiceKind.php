<?php

namespace App\Enums;

/** What an external service is (SPEC §3.5). */
enum ExternalServiceKind: string
{
    case Cdn = 'cdn';
    case Proxy = 'proxy';
    case Other = 'other';
}
