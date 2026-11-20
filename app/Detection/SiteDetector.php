<?php

namespace App\Detection;

use App\Data\Detection\BackendCandidateData;
use App\Data\Detection\BackendFindingData;
use App\Data\Detection\DetectedDomainData;
use App\Data\Detection\DetectedServerData;
use App\Data\Detection\DetectedServiceData;
use App\Data\Detection\DnsFindingData;
use App\Data\Detection\DnsRecordsData;
use App\Data\Detection\DomainFindingData;
use App\Data\Detection\DuplicateSiteData;
use App\Data\Detection\FrontFindingData;
use App\Data\Detection\ProbeSummaryData;
use App\Data\Detection\RegistryPreviewData;
use App\Data\Detection\SiteDetectionData;
use App\Enums\Confidence;
use App\Enums\Status;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Net\DnsResolver;
use App\Net\HttpProbe;
use App\Net\RdapClient;
use App\Net\Results\DnsAnswer;
use App\Net\Results\HttpResult;
use App\Net\Results\TlsResult;
use App\Net\TlsProbe;
use App\Net\WhoisClient;
use App\Net\WhoisParser;
use App\Support\Hostname;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Works out a site's domain, DNS, front service and backend from a URL
 * (SPEC §10.1–§10.3). Writes nothing. The network probes run in parallel,
 * so detection takes at most about 15 seconds.
 */
class SiteDetector
{
    public function __construct(
        private UrlNormalizer $normalizer,
        private PublicSuffixList $suffixes,
        private ExternalServiceMatcher $services,
        private ServerMatcher $servers,
        private ProposedMonitors $monitors,
    ) {}

    /**
     * @param  Site|null  $ignore  the site itself, when detection runs again on it (§10.7)
     *
     * @throws InvalidUrl
     */
    public function detect(string $input, ?Site $ignore = null): SiteDetectionData
    {
        $url = $this->normalizer->normalize($input);
        $result = [
            'input' => trim($input),
            'hostname' => $url->hostname,
            'display_hostname' => Hostname::display($url->hostname),
            'scheme' => $url->scheme,
            'port' => $url->port,
            'path' => $url->path,
            'duplicate' => null,
            'domain' => null,
            'dns' => null,
            'front' => null,
            'backend' => null,
            'backend_candidates' => [],
            'unknown_ip' => null,
            'probe' => null,
            'offer_www' => null,
            'monitors' => [],
        ];

        $duplicate = $this->duplicateOf($url->hostname, $ignore);

        if ($duplicate !== null) {
            return SiteDetectionData::from([...$result, 'duplicate' => $duplicate]);
        }

        $domainName = $this->suffixes->registrableDomain($url->hostname)
            ?? throw new InvalidUrl("{$url->hostname} is a public suffix, not a site.");
        $domain = Domain::query()->where('name', $domainName)->first();

        $probes = $this->probe($url, $domain === null ? $domainName : null);
        $dns = $probes['dns'];
        $http = $probes['http'] instanceof HttpResult ? $probes['http'] : new HttpResult([], null, null, error: (string) $probes['http']);
        $ips = $dns instanceof DnsAnswer ? $dns->ips() : [];
        $cnames = $dns instanceof DnsAnswer ? $dns->cnameChain : [];

        [$front, $unknownIp, $directServers] = $this->front($ips, $cnames, $http->ok() ? $http->headers : []);
        $frontService = $front->value === null ? null : ExternalService::query()->find($front->value->service_id);
        [$backend, $candidates] = $this->backend($url, $domain, $frontService, $directServers, $ips, $unknownIp, $http, $ignore);

        $expectedTarget = $http->ok() && $http->redirected() ? $this->withoutQuery((string) $http->finalUrl) : null;

        return SiteDetectionData::from([
            ...$result,
            'domain' => $this->domainFinding($domainName, $domain, $probes['registry']),
            'dns' => $this->dnsFinding($url->hostname, $dns),
            'front' => $front,
            'backend' => $backend,
            'backend_candidates' => $candidates,
            'unknown_ip' => $unknownIp,
            'probe' => $this->probeSummary($url, $http, $probes['tls']),
            'offer_www' => $this->offerWww($url->hostname, $http),
            'monitors' => [
                ...$this->monitors->forSite($url, $expectedTarget),
                ...($domain === null ? $this->monitors->forDomain() : []),
            ],
        ]);
    }

    private function duplicateOf(string $hostname, ?Site $ignore): ?DuplicateSiteData
    {
        $site = Site::withTrashed()
            ->with(['frontService', 'server'])
            ->where('hostname', $hostname)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->id))
            ->orderByRaw('deleted_at IS NULL DESC')
            ->latest('id')
            ->first();

        if ($site === null) {
            return null;
        }

        $path = $site->servingPath();

        return new DuplicateSiteData(
            site_id: $site->id,
            serving_path: implode(' → ', array_filter([$path->front, $path->backend ?? 'no backend'])),
            trashed: $site->trashed(),
        );
    }

    /**
     * DNS, HTTP, TLS and (for a new domain) the registry, at the same time.
     * Each result is the answer or the error message.
     *
     * @return array{dns: DnsAnswer|string, http: HttpResult|string, tls: TlsResult|string|null, registry: RegistryPreviewData|null}
     */
    private function probe(NormalizedUrl $url, ?string $newDomain): array
    {
        $hostname = $url->hostname;
        $target = $url->url();
        $port = $url->port ?? 443;
        $timeout = (float) min(10, (int) config('sextant.detection.timeout_seconds') - 3);

        $tasks = [
            'dns' => static fn () => app(DnsResolver::class)->resolve($hostname),
            'http' => static fn () => app(HttpProbe::class)->get($target, timeout: $timeout),
            'tls' => $url->scheme === 'https' ? static fn () => app(TlsProbe::class)->inspect($hostname, $port) : null,
            'registry' => $newDomain !== null ? static fn () => self::registryPreview($newDomain) : null,
        ];

        $tasks = array_filter($tasks);
        $guarded = array_map(fn (Closure $task): Closure => static function () use ($task): mixed {
            try {
                return $task();
            } catch (Throwable $exception) {
                return $exception->getMessage();
            }
        }, $tasks);

        try {
            $results = Concurrency::run($guarded);
        } catch (Throwable) {
            // SPEC: if worker processes can't start, probe one after another.
            $results = array_map(fn (Closure $task): mixed => $task(), $guarded);
        }

        /** @var array{dns: DnsAnswer|string, http: HttpResult|string, tls: TlsResult|string|null, registry: RegistryPreviewData|string|null} $results */
        $results = ['tls' => null, 'registry' => null, ...$results];

        if (is_string($results['registry'])) {
            $results['registry'] = new RegistryPreviewData('rdap', null, false, null, null, [], [], error: $results['registry']);
        }

        return $results;
    }

    /**
     * What the registry says about a new domain: RDAP, or WHOIS for TLDs without it (§9.3).
     */
    public static function registryPreview(string $domain): RegistryPreviewData
    {
        try {
            $rdap = app(RdapClient::class)->domain($domain);
        } catch (Throwable $exception) {
            return new RegistryPreviewData('rdap', null, false, null, null, [], [], error: $exception->getMessage());
        }

        if ($rdap !== null) {
            return new RegistryPreviewData(
                source: 'rdap',
                server: parse_url((string) $rdap->server, PHP_URL_HOST) ?: $rdap->server,
                found: $rdap->found,
                registrar: $rdap->registrar,
                expires_at: $rdap->expiresAt?->toDateString(),
                nameservers: $rdap->nameservers,
                statuses: $rdap->statuses,
            );
        }

        try {
            $raw = app(WhoisClient::class)->query($domain);
        } catch (Throwable $exception) {
            return new RegistryPreviewData('whois', null, false, null, null, [], [], error: $exception->getMessage());
        }

        $expires = WhoisParser::expiry($raw);

        return new RegistryPreviewData('whois', null, $expires !== null, WhoisParser::registrar($raw), $expires?->toDateString(), [], []);
    }

    private function domainFinding(string $name, ?Domain $domain, ?RegistryPreviewData $registry): DomainFindingData
    {
        $siteCount = $domain === null ? 0 : $domain->sites()->count();

        return new DomainFindingData(
            value: new DetectedDomainData($name, Hostname::display($name), $domain?->id, $siteCount, $registry),
            confidence: Confidence::Detected,
            explanation: $domain === null
                ? 'New domain. It is created with the site, and gets its own expiry and nameserver checks.'
                : 'Already in Sextant with '.trans_choice('{0} no sites|{1} 1 site|[2,*] :count sites', $siteCount).'. The new site is added under it.',
        );
    }

    private function dnsFinding(string $hostname, DnsAnswer|string $dns): DnsFindingData
    {
        $display = Hostname::display($hostname);

        if (is_string($dns)) {
            return new DnsFindingData(new DnsRecordsData([], []), Confidence::NotFound, "DNS lookup failed: {$dns}");
        }

        if (! $dns->resolves()) {
            return new DnsFindingData(
                new DnsRecordsData([], $dns->cnameChain),
                Confidence::NotFound,
                "{$display} does not resolve. Sextant carries on, but can't tell what's in front.",
            );
        }

        return new DnsFindingData(
            new DnsRecordsData($dns->ips(), $dns->cnameChain),
            Confidence::Detected,
            $dns->cnameChain === []
                ? "A and AAAA records for {$display}."
                : "{$display} is a CNAME to ".implode(' → ', $dns->cnameChain).'.',
        );
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $cnames
     * @param  array<string, string>  $headers
     * @return array{FrontFindingData, ?string, Collection<int, Server>}
     */
    private function front(array $ips, array $cnames, array $headers): array
    {
        $match = $this->services->match($ips, $cnames, $headers);

        if ($match !== null) {
            return [
                new FrontFindingData(new DetectedServiceData($match->service->id, $match->service->name), Confidence::Detected, $match->reason),
                null,
                collect(),
            ];
        }

        if ($ips === []) {
            return [new FrontFindingData(null, Confidence::NotFound, "Without DNS Sextant can't tell what's in front."), null, collect()];
        }

        $servers = $this->servers->forIps($ips);
        $unknown = collect($ips)->first(fn (string $ip): bool => $this->servers->forIp($ip) === null);

        if ($unknown !== null) {
            return [
                new FrontFindingData(null, Confidence::NotFound, "{$unknown} isn't one of our servers or a known external service."),
                $unknown,
                $servers,
            ];
        }

        return [
            new FrontFindingData(null, Confidence::Detected, 'Nothing in front: DNS points straight at '.$servers->pluck('name')->join(', ', ' and ').'.'),
            null,
            $servers,
        ];
    }

    /**
     * §10.3: a direct address, the backend header, the sibling sites, or nothing.
     *
     * @param  Collection<int, Server>  $directServers
     * @param  list<string>  $ips
     * @return array{BackendFindingData, list<BackendCandidateData>}
     */
    private function backend(
        NormalizedUrl $url,
        ?Domain $domain,
        ?ExternalService $front,
        Collection $directServers,
        array $ips,
        ?string $unknownIp,
        HttpResult $http,
        ?Site $ignore,
    ): array {
        if ($front === null) {
            if ($unknownIp === null && $directServers->count() === 1) {
                $server = $directServers->sole();
                $addresses = count($ips) === 1 ? "{$ips[0]} is" : 'The addresses are';

                return [new BackendFindingData(new DetectedServerData($server->id, $server->name), Confidence::Detected, "{$addresses} {$server->name}'s."), []];
            }

            if ($unknownIp === null && $directServers->count() > 1) {
                $candidates = $directServers->map(fn (Server $server): BackendCandidateData => new BackendCandidateData(
                    $server->id,
                    $server->name,
                    $domain === null ? 0 : $domain->sites()->where('server_id', $server->id)->count(),
                ))->values()->all();
                $candidates = array_values($candidates);

                return [new BackendFindingData(null, Confidence::NotFound, 'The addresses belong to '.$directServers->pluck('name')->join(', ', ' and ').'. Pick the server that builds the site.'), $candidates];
            }

            return [new BackendFindingData(null, Confidence::NotFound, $ips === []
                ? "The hostname doesn't resolve, so Sextant can't tell. Pick the server."
                : "{$unknownIp} isn't one of our servers. Add it as a server, or pick one."), []];
        }

        $header = config('sextant.detection.backend_header');

        if (is_string($header) && $header !== '' && ($named = $http->header($header)) !== null) {
            $server = Server::query()->where('name', trim($named))->first();

            if ($server !== null) {
                return [new BackendFindingData(new DetectedServerData($server->id, $server->name), Confidence::Detected, "The {$header} header names {$server->name}."), []];
            }
        }

        return $this->fromSiblings($url, $domain, $front, $ignore);
    }

    /**
     * The most common backend among the domain's other sites behind the same front.
     *
     * @return array{BackendFindingData, list<BackendCandidateData>}
     */
    private function fromSiblings(NormalizedUrl $url, ?Domain $domain, ExternalService $front, ?Site $ignore): array
    {
        $siblings = $domain === null ? collect() : Site::query()
            ->with('server')
            ->where('domain_id', $domain->id)
            ->where('front_service_id', $front->id)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->id))
            ->get();

        $candidates = $siblings
            ->filter(fn (Site $site): bool => $site->server !== null)
            ->groupBy('server_id')
            ->map(fn (Collection $sites): BackendCandidateData => new BackendCandidateData(
                (int) $sites->first()->server_id,
                (string) $sites->first()->server?->name,
                $sites->count(),
            ))
            ->sortBy([['sibling_count', 'desc'], ['name', 'asc']])
            ->values();

        $domainName = Hostname::display($domain === null ? (string) $this->suffixes->registrableDomain($url->hostname) : $domain->name);
        $cantSee = "Sextant can't see behind {$front->name}.";

        if ($candidates->isEmpty()) {
            return [new BackendFindingData(null, Confidence::NotFound, "{$cantSee} No other {$domainName} site behind it has a backend yet, so pick the server."), []];
        }

        /** @var BackendCandidateData $top */
        $top = $candidates->first();

        if ($candidates->count() > 1 && $candidates->get(1)?->sibling_count === $top->sibling_count) {
            $tied = $candidates->where('sibling_count', $top->sibling_count)->pluck('name')->join(', ', ' and ');

            return [new BackendFindingData(null, Confidence::NotFound, "{$cantSee} The {$domainName} sites behind it are split between {$tied}, so pick the server."), array_values($candidates->all())];
        }

        $explanation = $siblings->count() === 1
            ? "{$cantSee} The other {$domainName} site behind {$front->name} uses {$top->name}."
            : "{$cantSee} {$top->sibling_count} of the {$siblings->count()} {$domainName} sites behind {$front->name} use {$top->name}.";

        return [new BackendFindingData(new DetectedServerData($top->server_id, $top->name), Confidence::Suggested, $explanation), array_values($candidates->all())];
    }

    private function probeSummary(NormalizedUrl $url, HttpResult $http, TlsResult|string|null $tls): ProbeSummaryData
    {
        $httpLine = $http->ok()
            ? "{$http->status} in ".($http->timings['total'] ?? 0).' ms'
            : 'Failed: '.($http->error ?? 'no response');
        $httpStatus = $http->ok() && $http->status < 400 ? Status::Ok : Status::Error;

        [$tlsLine, $tlsStatus] = match (true) {
            $url->scheme !== 'https' => [null, null],
            is_string($tls) || $tls === null => ["Couldn't check: ".($tls ?? 'no connection'), Status::Unknown],
            ! $tls->verified || $tls->certificate === null => ['Not valid: '.($tls->verifyError ?? 'verification failed'), Status::Error],
            default => $this->certificateLine($tls),
        };

        [$redirect, $redirectStatus] = match (true) {
            ! $http->ok() => ['Unknown until the site answers', Status::Unknown],
            ! $http->redirected() => ['No redirect', Status::Unknown],
            default => $this->redirectLine($url, $http),
        };

        return new ProbeSummaryData($httpLine, $httpStatus, $tlsLine, $tlsStatus, $redirect, $redirectStatus, $http->finalUrl);
    }

    /**
     * @return array{string, Status}
     */
    private function certificateLine(TlsResult $tls): array
    {
        $certificate = $tls->certificate;
        $timezone = (string) config('sextant.display_timezone');
        $expires = $certificate->expiresAt->setTimezone($timezone);
        $days = (int) CarbonImmutable::now($timezone)->startOfDay()->diffInDays($expires->startOfDay(), false);

        return [
            "{$certificate->issuer} · valid until {$expires->format('j M Y')} (".trans_choice('{1} 1 day|[2,*] :count days|[*,0] expired', $days).')',
            match (true) {
                $days <= 7 => Status::Error,
                $days <= 30 => Status::Warning,
                default => Status::Ok,
            },
        ];
    }

    /**
     * @return array{string, Status}
     */
    private function redirectLine(NormalizedUrl $url, HttpResult $http): array
    {
        $final = (string) $http->finalUrl;
        $hops = count($http->chain) - 1;
        $line = "Redirects to {$final}".($hops > 1 ? " ({$hops} hops)" : '');
        $finalHost = (string) parse_url($final, PHP_URL_HOST);
        $toHttp = $url->scheme === 'https' && str_starts_with($final, 'http://');
        $elsewhere = $this->suffixes->registrableDomain($finalHost) !== $this->suffixes->registrableDomain($url->hostname);

        return [$line, $toHttp || $elsewhere ? Status::Warning : Status::Ok];
    }

    /**
     * Offer the `www.` sibling when the URL redirects there and it isn't a site yet.
     */
    private function offerWww(string $hostname, HttpResult $http): ?string
    {
        if (! $http->ok() || ! $http->redirected() || str_starts_with($hostname, 'www.')) {
            return null;
        }

        $www = 'www.'.$hostname;

        if (strtolower((string) parse_url((string) $http->finalUrl, PHP_URL_HOST)) !== $www) {
            return null;
        }

        return Site::query()->where('hostname', $www)->exists() ? null : $www;
    }

    private function withoutQuery(string $url): string
    {
        return (string) preg_replace('/[?#].*$/', '', $url);
    }
}
