<?php

namespace App\Rules;

use App\Detection\ExternalServiceMatcher;
use App\Models\Server;
use App\Models\ServerAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An IP may belong to at most one non-deleted server and must not fall inside
 * an external service's ranges, its own or the published ones (SPEC §3.3).
 * Sextant never creates a server from an external service's address (GOAL §3).
 */
class IpAddressIsFree implements ValidationRule
{
    public function __construct(private ?Server $ignoreServer = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return;
        }

        $ip = (string) inet_ntop((string) inet_pton($value));

        $owner = ServerAddress::query()
            ->where('ip', $ip)
            ->whereHas('server')
            ->when($this->ignoreServer, fn ($query, Server $server) => $query->where('server_id', '!=', $server->id))
            ->with('server')
            ->first();

        if ($owner !== null) {
            $fail("{$ip} already belongs to {$owner->server->name}.");

            return;
        }

        $service = app(ExternalServiceMatcher::class)->forIp($ip);

        if ($service !== null) {
            $fail("{$ip} is in the ranges of the external service {$service->name}, so it can't be one of our servers.");
        }
    }
}
