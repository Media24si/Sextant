<?php

namespace App\Checks\Site;

use App\Checks\Arguments;
use App\Checks\CheckResult;
use App\Checks\SiteChecker;
use App\Detection\BackendSuggester;
use App\Detection\ExternalServiceMatcher;
use App\Detection\ServerMatcher;
use App\Enums\Confidence;
use App\Models\Server;
use App\Models\Site;
use App\Net\DnsResolver;

/**
 * `site.path`: does DNS still point at the first step of the site's serving
 * path (SPEC §9.7)? Every situation in §9.7's table is a Warning
 * (`max_severity: warning`).
 */
class ServingPathChecker extends SiteChecker
{
    public function __construct(
        private DnsResolver $dns,
        private ExternalServiceMatcher $services,
        private ServerMatcher $servers,
        private BackendSuggester $suggester,
    ) {}

    public static function name(): string
    {
        return 'Serving path';
    }

    public static function trackedFields(): array
    {
        return [
            'ips' => 'site.dns_changed',
            'matched_service' => 'site.front_detected_changed',
        ];
    }

    public static function describeChange(string $field, mixed $old, mixed $new): string
    {
        return $field === 'ips'
            ? 'DNS changed from '.self::plainValue($old).' to '.self::plainValue($new)
            : 'DNS now points at '.($new === null ? 'no external service' : self::plainValue($new)).' (was '.($old === null ? 'none' : self::plainValue($old)).')';
    }

    protected function check(Site $site, Arguments $arguments): CheckResult
    {
        $answer = $this->dns->resolve($site->hostname);
        $ips = $answer->ips();
        $front = $site->frontService;
        $backend = $site->server;

        $service = $answer->resolves() ? $this->services->match($ips, $answer->cnameChain)?->service : null;
        $servers = $this->servers->forIps($ips);
        $unknown = collect($ips)->first(fn (string $ip): bool => $this->services->forIp($ip) === null && $this->servers->forIp($ip) === null);

        $data = [
            // CDN addresses rotate, so they aren't tracked behind a front service.
            'ips' => $front === null ? $ips : null,
            'cname_chain' => $answer->cnameChain,
            'matched_service' => $service?->name,
            'matched_servers' => $servers->pluck('name')->all(),
        ];

        if (! $answer->resolves()) {
            return CheckResult::warning('Hostname does not resolve', $data);
        }

        if ($service !== null) {
            if ($front === null) {
                return CheckResult::warning("Resolves to {$service->name}, path says direct", $data);
            }

            if ($service->isNot($front)) {
                return CheckResult::warning("Resolves to {$service->name}, path says {$front->name}", $data);
            }

            return $backend === null
                ? CheckResult::warning($this->notAssigned($site), $data)
                : CheckResult::ok("Via {$front->name} to {$backend->name}", $data);
        }

        if ($unknown !== null) {
            return CheckResult::warning("Resolves to unknown IP {$unknown}", $data);
        }

        $names = $servers->pluck('name')->join(', ', ' and ');

        if ($front !== null) {
            return CheckResult::warning("Resolves directly to {$names}, path says behind {$front->name}", $data);
        }

        if ($backend === null) {
            return CheckResult::warning($this->notAssigned($site), $data);
        }

        $others = $servers->reject(fn (Server $server): bool => $server->is($backend));

        return $others->isEmpty()
            ? CheckResult::ok("Direct to {$backend->name}", $data)
            : CheckResult::warning('Resolves to '.$others->pluck('name')->join(', ', ' and ').", backend is {$backend->name}", $data);
    }

    /** "Backend not assigned", with detection's suggestion when there is one (§10.3). */
    private function notAssigned(Site $site): string
    {
        if ($site->frontService === null) {
            return 'Backend not assigned';
        }

        [$finding] = $this->suggester->suggest($site->domain, $site->domain->display_name, $site->frontService, $site);

        return 'Backend not assigned'.($finding->confidence === Confidence::Suggested ? " (suggested: {$finding->value?->name})" : '');
    }
}
