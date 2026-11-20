<?php

namespace App\Detection;

use App\Enums\RangeSource;
use App\Rules\IpOrCidr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * IP ranges that CDNs publish (config('sextant.range_sources')), downloaded
 * daily by `proxies:refresh`. Kept in the cache and in
 * storage/app/proxy-ranges.json, the fallback when a download fails (SPEC §15.1).
 */
class PublishedRanges
{
    private const CACHE_KEY = 'published-ranges';

    private const FILE = 'proxy-ranges.json';

    /**
     * @return list<string>
     */
    public function for(RangeSource $source): array
    {
        return $this->all()[$source->value] ?? [];
    }

    /**
     * Download every source. A source that fails keeps its previous ranges.
     *
     * @return array<string, int|string> source => number of ranges, or the error
     */
    public function refresh(): array
    {
        $ranges = $this->all();
        $report = [];

        foreach (RangeSource::cases() as $source) {
            try {
                $downloaded = $this->download($source);

                if ($downloaded === []) {
                    throw new \RuntimeException('no ranges in the response');
                }

                $ranges[$source->value] = $downloaded;
                $report[$source->value] = count($downloaded);
            } catch (Throwable $exception) {
                $report[$source->value] = $exception->getMessage();
            }
        }

        Storage::put(self::FILE, (string) json_encode($ranges, JSON_PRETTY_PRINT));
        Cache::forever(self::CACHE_KEY, $ranges);

        return $report;
    }

    /**
     * @return array<string, list<string>>
     */
    private function all(): array
    {
        /** @var array<string, list<string>>|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        $stored = Storage::exists(self::FILE) ? json_decode((string) Storage::get(self::FILE), true) : null;
        $ranges = is_array($stored) ? $stored : [];
        Cache::forever(self::CACHE_KEY, $ranges);

        return $ranges;
    }

    /**
     * @return list<string>
     */
    private function download(RangeSource $source): array
    {
        $ranges = [];

        foreach ((array) config("sextant.range_sources.{$source->value}") as $url) {
            [$address, $fragment] = array_pad(explode('#', (string) $url, 2), 2, null);
            $response = Http::withUserAgent((string) config('sextant.user_agent'))->timeout(20)->get($address);

            if ($response->failed()) {
                throw new \RuntimeException("HTTP {$response->status()} from {$address}");
            }

            $ranges = [...$ranges, ...$this->parse($source, $response->body(), $fragment)];
        }

        return array_values(array_unique(array_filter($ranges, fn (string $range): bool => IpOrCidr::passes($range))));
    }

    /**
     * Cloudflare: one CIDR per line. Fastly: {addresses, ipv6_addresses}.
     * CloudFront: AWS ip-ranges.json, filtered by the service in the URL fragment.
     *
     * @return list<string>
     */
    private function parse(RangeSource $source, string $body, ?string $service): array
    {
        if ($source === RangeSource::Cloudflare) {
            return array_values(array_filter(array_map('trim', explode("\n", $body))));
        }

        $json = json_decode($body, true);

        if (! is_array($json)) {
            return [];
        }

        if ($source === RangeSource::Fastly) {
            return array_values(array_map('strval', [...(array) ($json['addresses'] ?? []), ...(array) ($json['ipv6_addresses'] ?? [])]));
        }

        $ranges = [];

        foreach ([['prefixes', 'ip_prefix'], ['ipv6_prefixes', 'ipv6_prefix']] as [$list, $key]) {
            foreach ((array) ($json[$list] ?? []) as $prefix) {
                if (is_array($prefix) && ($service === null || ($prefix['service'] ?? null) === $service) && is_string($prefix[$key] ?? null)) {
                    $ranges[] = $prefix[$key];
                }
            }
        }

        return $ranges;
    }
}
