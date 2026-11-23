<?php

namespace App\Github;

use App\Models\Application;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * A repository named by a site's or application's GitHub link
 * (`https://github.com/{owner}/{name}`, SPEC §3.7, §3.19).
 */
final readonly class GithubRepo
{
    public function __construct(
        public string $owner,
        public string $name,
        public string $url,
    ) {}

    public static function fromUrl(?string $url): ?self
    {
        if ($url === null || preg_match('#^https://github\.com/([A-Za-z0-9][A-Za-z0-9-]{0,38})/([A-Za-z0-9._-]{1,100})$#', $url, $match) !== 1) {
            return null;
        }

        $name = preg_replace('/\.git$/', '', $match[2]) ?? $match[2];

        return $name === '' ? null : new self($match[1], $name, $url);
    }

    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }

    /**
     * Every repository linked from a live site or application, once per
     * link (GitHub links are case-insensitive), sorted by owner and name.
     *
     * @return Collection<string, self> keyed by lowercase URL
     */
    public static function linked(): Collection
    {
        $urls = Site::query()->whereNotNull('github_url')->pluck('github_url')
            ->merge(Application::query()->whereNotNull('github_url')->pluck('github_url'));

        return $urls
            ->map(fn (string $url): ?self => self::fromUrl($url))
            ->filter()
            ->keyBy(fn (self $repo): string => strtolower($repo->url))
            ->sortBy(fn (self $repo): string => strtolower($repo->fullName()));
    }
}
