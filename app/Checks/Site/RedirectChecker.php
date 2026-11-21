<?php

namespace App\Checks\Site;

use App\Checks\Argument;
use App\Checks\Arguments;
use App\Checks\CheckCouldNotRun;
use App\Checks\CheckResult;
use App\Checks\SiteChecker;
use App\Detection\PublicSuffixList;
use App\Models\Site;
use App\Net\HttpProbe;

/**
 * `site.redirect` (SPEC §9.8): follows each redirect hop by hand and warns
 * about loops, long chains, HTTPS → HTTP hops, and a final URL other than the
 * expected one (or, without one, on another registrable domain).
 */
class RedirectChecker extends SiteChecker
{
    public function __construct(
        private HttpProbe $http,
        private PublicSuffixList $suffixes,
    ) {}

    public static function name(): string
    {
        return 'Redirect';
    }

    public static function arguments(): array
    {
        return [
            Argument::string('path', 'Path')->rules(['required', 'max:255', 'starts_with:/']),
            Argument::string('expected_target', 'Expected final URL')->nullable()->rules(['max:2048', 'url:http,https'])
                ->help('Empty = any URL on the same domain'),
            Argument::int('max_redirects', 'Most redirects allowed')->rules(['between:1,5']),
        ];
    }

    public static function trackedFields(): array
    {
        return ['final_url' => 'redirect.target_changed'];
    }

    public static function describeChange(string $field, mixed $old, mixed $new): string
    {
        return 'now redirects to '.self::plainValue($new).' (was '.self::plainValue($old).')';
    }

    protected function check(Site $site, Arguments $arguments): CheckResult
    {
        $result = $this->http->get($site->url.$arguments->string('path'), followRedirects: true);
        $chain = $result->chain;

        if ($chain === []) {
            throw new CheckCouldNotRun('No response: '.($result->error ?? 'connection failed'));
        }

        $urls = array_map(fn (array $hop): string => $hop['url'], $chain);
        $final = (string) ($result->finalUrl ?? end($urls));
        $hops = count($chain) - 1;
        $data = ['chain' => $chain, 'final_url' => $final, 'final_status' => $result->status];

        if (count($urls) !== count(array_unique($urls))) {
            return CheckResult::warning('Redirect loop: '.implode(' → ', $urls), $data);
        }

        if ($result->error !== null && $result->status === null) {
            return $hops >= $arguments->int('max_redirects')
                ? CheckResult::warning("More than {$arguments->int('max_redirects')} redirects", $data)
                : throw new CheckCouldNotRun("Redirect to {$final} failed: {$result->error}");
        }

        if ($hops > $arguments->int('max_redirects')) {
            return CheckResult::warning("{$hops} redirects (more than {$arguments->int('max_redirects')})", $data);
        }

        for ($i = 1; $i < count($urls); $i++) {
            if (str_starts_with($urls[$i - 1], 'https://') && str_starts_with($urls[$i], 'http://')) {
                return CheckResult::warning("Redirects from HTTPS to HTTP: {$urls[$i - 1]} → {$urls[$i]}", $data);
            }
        }

        $expected = $arguments->nullableString('expected_target');

        if ($expected !== null) {
            if ($this->same($final, $expected)) {
                return CheckResult::ok($hops === 0 ? 'No redirect, as expected' : "Redirects to {$final} as expected", $data);
            }

            return CheckResult::warning(($hops === 0 ? 'No redirect' : "Redirects to {$final}").", expected {$expected}", $data);
        }

        if ($hops === 0) {
            return CheckResult::ok('No redirect', $data);
        }

        $finalDomain = $this->suffixes->registrableDomain((string) parse_url($final, PHP_URL_HOST));

        if ($finalDomain !== $this->suffixes->registrableDomain($site->hostname)) {
            return CheckResult::warning("Redirects to another domain: {$final}", $data);
        }

        return CheckResult::ok("Redirects to {$final}", $data);
    }

    /** Scheme, host and path; the query is ignored (§9.8). */
    private function same(string $a, string $b): bool
    {
        $key = function (string $url): string {
            $parts = parse_url($url) ?: [];

            return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '').(($parts['path'] ?? '') === '' ? '/' : $parts['path']);
        };

        return $key($a) === $key($b);
    }
}
