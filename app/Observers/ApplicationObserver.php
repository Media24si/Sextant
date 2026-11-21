<?php

namespace App\Observers;

use App\Models\Application;

class ApplicationObserver extends InventoryObserver
{
    public function created(Application $application): void
    {
        $this->record('application.created', $application, "Application {$application->name} added");
    }

    public function updated(Application $application): void
    {
        $changes = $this->changes($application);

        if ($changes !== []) {
            $this->record('application.updated', $application, "Application {$application->name} changed: ".implode(', ', array_keys($changes)), $changes);
        }
    }

    public function deleted(Application $application): void
    {
        $this->record('application.deleted', $application, "Application {$application->name} deleted");
    }

    public function restored(Application $application): void
    {
        $this->record('application.restored', $application, "Application {$application->name} restored");
    }
}
