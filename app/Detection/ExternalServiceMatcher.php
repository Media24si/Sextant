<?php

namespace App\Detection;

use App\Models\ExternalService;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which external service answers for a hostname (SPEC §9.0): by address
 * (admin ranges plus published ranges), then CNAME suffix, then a response
 * header that gives it away. No network.
 */
class ExternalServiceMatcher
{
    public function __construct(private PublishedRanges $published) {}

    /**
     * Every range a service answers on: its own plus its published ones.
     *
     * @return list<string>
     */
    public function rangesOf(ExternalService $service): array
    {
        return [
            ...$service->ranges,
            ...($service->ranges_source !== null ? $this->published->for($service->ranges_source) : []),
        ];
    }

    public function forIp(string $ip): ?ExternalService
    {
        return $this->services()->first(fn (ExternalService $service): bool => IpUtils::checkIp($ip, $this->rangesOf($service)));
    }

    /**
     * @param  list<string>  $ips
     * @param  list<string>  $cnameChain
     * @param  array<string, string>  $headers  lowercase names
     */
    public function match(array $ips, array $cnameChain = [], array $headers = []): ?ExternalServiceMatch
    {
        foreach ($ips as $ip) {
            $service = $this->forIp($ip);

            if ($service !== null) {
                $reason = count($ips) > 1
                    ? "The addresses are in {$service->name}'s ranges."
                    : "{$ip} belongs to the external service {$service->name}.";

                return new ExternalServiceMatch($service, $reason);
            }
        }

        foreach ($this->services() as $service) {
            foreach ($service->cname_suffixes ?? [] as $suffix) {
                foreach ($cnameChain as $cname) {
                    if (str_ends_with($cname, $suffix)) {
                        return new ExternalServiceMatch($service, "The hostname is a CNAME to {$cname}, a {$service->name} name.");
                    }
                }
            }
        }

        foreach ($this->services() as $service) {
            foreach ($service->response_headers ?? [] as $header) {
                if (array_key_exists(strtolower($header), $headers)) {
                    return new ExternalServiceMatch($service, "The response carries {$service->name}'s {$header} header.");
                }
            }
        }

        return null;
    }

    /**
     * @return Collection<int, ExternalService>
     */
    private function services(): Collection
    {
        return ExternalService::query()->orderBy('id')->get();
    }
}
