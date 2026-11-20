<?php

namespace App\Detection;

/**
 * A URL cleaned up by UrlNormalizer (SPEC §10.1 step 1).
 */
final readonly class NormalizedUrl
{
    public function __construct(
        public string $scheme,
        /** Lowercase ASCII (punycode), no trailing dot. */
        public string $hostname,
        /** Null = the scheme's default port. */
        public ?int $port,
        /** For the HTTP monitor; `/` when the URL had none. */
        public string $path,
    ) {}

    /** `scheme://hostname[:port]`, like `$site->url`. */
    public function origin(): string
    {
        return $this->scheme.'://'.$this->hostname.($this->port !== null ? ':'.$this->port : '');
    }

    public function url(): string
    {
        return $this->origin().$this->path;
    }
}
