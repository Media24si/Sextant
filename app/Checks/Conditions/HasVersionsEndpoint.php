<?php

namespace App\Checks\Conditions;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;

/** `server.versions` only once a versions endpoint and token are set (SPEC §4.4, §11.7). */
class HasVersionsEndpoint implements Condition
{
    public function __invoke(Model $resource, array $arguments): bool
    {
        return $resource instanceof Server && $resource->versions_url !== null && $resource->versions_token !== null;
    }
}
