<?php

namespace App\Detection;

use App\Net\WebClient;
use Illuminate\Support\Facades\Storage;
use Pdp\Rules;
use Throwable;

/**
 * The registrable domain of a hostname from the Public Suffix List
 * (SPEC §2, §10.1). The list is refreshed weekly into storage; a committed
 * snapshot in resources/data is the fallback.
 */
class PublicSuffixList
{
    public const URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    private const FILE = 'public_suffix_list.dat';

    private ?Rules $rules = null;

    /**
     * `podjetje.svet24.si` → `svet24.si`; null for a bare suffix or an
     * unknown TLD. Takes and returns ASCII (punycode).
     */
    public function registrableDomain(string $hostname): ?string
    {
        try {
            $domain = $this->rules()->getICANNDomain($hostname)->registrableDomain()->toString();
        } catch (Throwable) {
            return null;
        }

        return $domain === '' ? null : $domain;
    }

    /**
     * Download the list again (weekly, `rdap:refresh-bootstrap`).
     */
    public function refresh(): void
    {
        $response = app(WebClient::class)->get(self::URL, timeout: 30);
        $content = $response->body();

        // Refuse anything that doesn't look like the list, so a bad download never replaces a good copy.
        if ($response->failed() || ! str_contains($content, '===BEGIN ICANN DOMAINS===')) {
            throw new \RuntimeException("Could not download the Public Suffix List (HTTP {$response->status()}).");
        }

        Storage::put(self::FILE, $content);
        $this->rules = null;
    }

    private function rules(): Rules
    {
        return $this->rules ??= Storage::exists(self::FILE)
            ? Rules::fromString((string) Storage::get(self::FILE))
            : Rules::fromPath(resource_path('data/public_suffix_list.dat'));
    }
}
