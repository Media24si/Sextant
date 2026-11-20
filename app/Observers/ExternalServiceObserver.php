<?php

namespace App\Observers;

use App\Models\ExternalService;

class ExternalServiceObserver extends InventoryObserver
{
    public function created(ExternalService $service): void
    {
        $this->record('external_service.created', $service, "External service {$service->name} added");
    }

    public function updated(ExternalService $service): void
    {
        $changes = $this->changes($service);

        if ($changes !== []) {
            $this->record('external_service.updated', $service, "External service {$service->name} changed: ".implode(', ', array_keys($changes)), $changes);
        }
    }

    public function deleted(ExternalService $service): void
    {
        $this->record('external_service.deleted', $service, "External service {$service->name} deleted");
    }

    public function restored(ExternalService $service): void
    {
        $this->record('external_service.restored', $service, "External service {$service->name} restored");
    }
}
