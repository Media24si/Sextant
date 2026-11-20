<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A `return_to` path for forms opened from another page, e.g. Add server
 * from Add site's unknown address (SPEC §10.4). Only paths on this app.
 */
class ReturnTo
{
    public static function from(Request $request): ?string
    {
        $path = $request->input('return_to');

        return is_string($path) && preg_match('#^/(?![/\\\\])[^\s]*$#', $path) === 1 ? $path : null;
    }
}
