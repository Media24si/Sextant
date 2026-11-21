<?php

namespace App\Checks\Site;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckResult;
use App\Checks\SiteChecker;
use App\Models\Site;
use App\Net\HttpProbe;

/** `site.http`: does the site answer with the expected status, in time (SPEC §9.5)? */
class HttpChecker extends SiteChecker
{
    /** At most this much of the body is read, and only when `contains` is set. */
    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    public function __construct(private HttpProbe $http) {}

    public static function name(): string
    {
        return 'HTTP';
    }

    public static function arguments(): array
    {
        return [
            Argument::string('path', 'Path')->rules(['required', 'max:255', 'starts_with:/']),
            Argument::intList('expected_status', 'Expected status codes')->rules(['required', 'min:1']),
            Argument::int('timeout', 'Timeout (seconds)')->rules(['between:1,30']),
            Argument::int('slow_ms', 'Slow above (ms)')->rules(['between:100,60000']),
            Argument::bool('verify_tls', 'Verify the certificate'),
            Argument::string('contains', 'Page must contain')->nullable()->rules(['max:255'])->help('Empty = no text check'),
        ];
    }

    protected function check(Site $site, Arguments $arguments): CheckResult
    {
        $contains = $arguments->nullableString('contains');
        $result = $this->http->get(
            $site->url.$arguments->string('path'),
            followRedirects: true,
            maxBytes: $contains === null ? 0 : self::MAX_BODY_BYTES,
            timeout: $arguments->int('timeout'),
            verifyTls: $arguments->bool('verify_tls'),
        );

        $data = [
            'final_url' => $result->finalUrl,
            'status_code' => $result->status,
            'redirects' => max(0, count($result->chain) - 1),
            'total_ms' => $result->timings['total'] ?? null,
            'dns_ms' => $result->timings['dns'] ?? null,
            'connect_ms' => $result->timings['connect'] ?? null,
            'tls_ms' => $result->timings['tls'] ?? null,
            'ttfb_ms' => $result->timings['ttfb'] ?? null,
            'ip' => $result->ip,
            'http_version' => null,
        ];

        if (! $result->ok()) {
            return CheckResult::error((string) ($result->error ?? 'No response'), $data);
        }

        $expected = $arguments->intList('expected_status');
        $ms = (int) ($result->timings['total'] ?? 0);

        if (! in_array($result->status, $expected, true)) {
            return CheckResult::error("{$result->status} in {$ms} ms (expected ".implode(', ', $expected).')', $data);
        }

        if ($contains !== null && ! str_contains((string) $result->body, $contains)) {
            return CheckResult::error("{$result->status}, but the page doesn't contain \"{$contains}\"", $data);
        }

        if ($ms > $arguments->int('slow_ms')) {
            return CheckResult::warning("{$result->status} in {$ms} ms (slow, over {$arguments->int('slow_ms')} ms)", $data);
        }

        return CheckResult::ok("{$result->status} in {$ms} ms", $data);
    }
}
