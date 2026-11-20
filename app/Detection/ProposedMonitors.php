<?php

namespace App\Detection;

use App\Data\Detection\ProposedMonitorData;

/**
 * The default monitors (SPEC §4.4) that detection proposes, with the detected
 * arguments as overrides (§10.1 step 8).
 *
 * SPEC: `config/checkers.php` arrives in P3.3. Until then the defaults are
 * listed here; P3 replaces this with the registry's `defaults`.
 */
class ProposedMonitors
{
    /**
     * @return list<ProposedMonitorData>
     */
    public function forSite(NormalizedUrl $url, ?string $expectedTarget): array
    {
        $path = $url->path === '/' ? [] : ['path' => $url->path];

        return array_values(array_filter([
            new ProposedMonitorData('site.http', null, $path),
            $url->scheme === 'https' ? new ProposedMonitorData('site.ssl', null, $url->port !== null ? ['port' => $url->port] : []) : null,
            new ProposedMonitorData('site.path', null, []),
            new ProposedMonitorData('site.redirect', null, array_filter([
                ...$path,
                'expected_target' => $expectedTarget,
            ], fn (mixed $value): bool => $value !== null)),
        ]));
    }

    /**
     * @return list<ProposedMonitorData>
     */
    public function forDomain(): array
    {
        return [
            new ProposedMonitorData('domain.expiration', null, []),
            new ProposedMonitorData('domain.nameservers', null, []),
        ];
    }
}
