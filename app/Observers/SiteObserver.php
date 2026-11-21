<?php

namespace App\Observers;

use App\Enums\IncidentResolution;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\Site;
use App\Monitoring\IncidentManager;
use App\Monitoring\MonitorEffects;

class SiteObserver extends InventoryObserver
{
    private const PATH = ['front_service_id', 'server_id'];

    public function created(Site $site): void
    {
        $this->record('site.created', $site, "Site {$site->display_hostname} added");
    }

    public function updated(Site $site): void
    {
        $path = $this->changes($site, self::PATH);

        if ($path !== []) {
            $old = $this->describePath(
                $site->getOriginal('front_service_id'),
                $site->getOriginal('server_id'),
            );
            $new = $this->describePath($site->front_service_id, $site->server_id);

            $this->record('site.path_changed', $site, "{$site->display_hostname} serving path: {$new} (was {$old})", [
                ...$path,
                'path' => ['old' => $old, 'new' => $new],
            ]);
            MonitorEffects::runSoon($site, 'site.path');
        }

        $other = array_diff_key($this->changes($site), array_flip(self::PATH));

        if ($other !== []) {
            $this->record('site.updated', $site, "Site {$site->display_hostname} changed: ".implode(', ', array_keys($other)), $other);
        }
    }

    public function deleted(Site $site): void
    {
        $this->record('site.deleted', $site, "Site {$site->display_hostname} deleted");
        app(IncidentManager::class)->resolveFor($site->monitors()->get(), IncidentResolution::ResourceDeleted);
    }

    public function restored(Site $site): void
    {
        $this->record('site.restored', $site, "Site {$site->display_hostname} restored");
        MonitorEffects::runSoon($site);
    }

    /**
     * "Varnish → web02", "direct → web02", "Cloudflare → backend not assigned".
     */
    private function describePath(mixed $frontId, mixed $serverId): string
    {
        $front = is_numeric($frontId) ? ExternalService::withTrashed()->find((int) $frontId)?->name : null;
        $backend = is_numeric($serverId) ? Server::withTrashed()->find((int) $serverId)?->name : null;

        return ($front ?? 'direct').' → '.($backend ?? 'backend not assigned');
    }
}
