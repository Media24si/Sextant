<?php

namespace App\Detection;

use App\Checks\CheckerRegistry;
use App\Checks\Conditions\Condition;
use App\Data\Detection\ProposedMonitorData;
use App\Models\Site;

/**
 * The default monitors (SPEC §4.4) that detection proposes, with the detected
 * arguments as overrides (§10.1 step 8): the path, the redirect target and a
 * non-default port.
 */
class ProposedMonitors
{
    public function __construct(private CheckerRegistry $registry) {}

    /**
     * @return list<ProposedMonitorData>
     */
    public function forSite(NormalizedUrl $url, ?string $expectedTarget): array
    {
        $site = new Site(['scheme' => $url->scheme, 'hostname' => $url->hostname, 'port' => $url->port]);
        $detected = array_filter([
            'path' => $url->path === '/' ? null : $url->path,
            'expected_target' => $expectedTarget,
            'port' => $url->scheme === 'https' ? $url->port : null,
        ], fn (mixed $value): bool => $value !== null);

        $proposed = [];

        foreach ($this->registry->defaults('site') as $default) {
            $arguments = $default['arguments'] ?? [];
            $condition = $default['only_if'] ?? null;

            /** @var Condition|null $check */
            $check = $condition === null ? null : app($condition);

            if ($check !== null && ! $check($site, $arguments)) {
                continue;
            }

            $names = array_column($this->registry->schema($default['checker']), 'name');

            $proposed[] = new ProposedMonitorData(
                $default['checker'],
                $default['label'] ?? null,
                [...$arguments, ...array_intersect_key($detected, array_flip($names))],
            );
        }

        return $proposed;
    }

    /**
     * @return list<ProposedMonitorData>
     */
    public function forDomain(): array
    {
        return array_map(
            fn (array $default): ProposedMonitorData => new ProposedMonitorData($default['checker'], $default['label'] ?? null, $default['arguments'] ?? []),
            $this->registry->defaults('domain'),
        );
    }
}
