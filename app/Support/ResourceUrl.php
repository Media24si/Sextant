<?php

namespace App\Support;

/**
 * The page of a resource by its morph type and id, for links in the change
 * feed and search. Null when the resource has no page of its own.
 */
class ResourceUrl
{
    public static function for(string $type, int $id): ?string
    {
        return match ($type) {
            'server' => route('servers.show', $id, false),
            'domain' => route('domains.show', $id, false),
            'site' => route('sites.show', $id, false),
            'external_service' => route('external-services.index', [], false),
            'minimum_version' => route('minimum-versions.index', [], false),
            default => null,
        };
    }
}
