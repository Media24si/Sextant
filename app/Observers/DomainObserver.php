<?php

namespace App\Observers;

use App\Models\Domain;
use App\Monitoring\MonitorEffects;

class DomainObserver extends InventoryObserver
{
    protected function excluded(): array
    {
        return ['expires_at', 'registrar'];
    }

    public function created(Domain $domain): void
    {
        $this->record('domain.created', $domain, "Domain {$domain->display_name} added");
    }

    public function updated(Domain $domain): void
    {
        $changes = $this->changes($domain);

        if ($changes !== []) {
            $this->record('domain.updated', $domain, "Domain {$domain->display_name} changed: ".implode(', ', array_keys($changes)), $changes);
        }
    }

    public function deleted(Domain $domain): void
    {
        $this->record('domain.deleted', $domain, "Domain {$domain->display_name} deleted");
    }

    public function restored(Domain $domain): void
    {
        $this->record('domain.restored', $domain, "Domain {$domain->display_name} restored");
        MonitorEffects::runSoon($domain);
    }
}
