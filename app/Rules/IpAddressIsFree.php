<?php

namespace App\Rules;

use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An IP may belong to at most one non-deleted server and must not fall inside
 * an external service's ranges (SPEC §3.3). Sextant never creates a server
 * from an external service's address (GOAL §3).
 *
 * SPEC: only the admin-entered `ranges` are checked here; the published
 * ranges of `ranges_source` services join when `proxies:refresh` caches them (P2.3/P2.4).
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

        foreach (ExternalService::query()->get(['name', 'ranges']) as $service) {
            if (IpUtils::checkIp($ip, $service->ranges)) {
                $fail("{$ip} is in the ranges of the external service {$service->name}, so it can't be one of our servers.");

                return;
            }
        }
    }
}
