<?php

namespace App\Enums;

/**
 * Providers whose published IP ranges `proxies:refresh` downloads (SPEC §3.5,
 * config('sextant.range_sources')).
 */
enum RangeSource: string
{
    case Cloudflare = 'cloudflare';
    case Fastly = 'fastly';
    case Cloudfront = 'cloudfront';
}
