<?php

namespace App\Checks\Conditions;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

/** `site.ssl` only on https sites (SPEC §9.6). */
class UsesHttps implements Condition
{
    public function __invoke(Model $resource, array $arguments): bool
    {
        return $resource instanceof Site && $resource->scheme === 'https';
    }
}
