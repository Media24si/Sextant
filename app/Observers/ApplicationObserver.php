<?php

namespace App\Observers;

use App\Models\Application;
use App\Packages\RepositorySync;

class ApplicationObserver extends InventoryObserver
{
    public function created(Application $application): void
    {
        $this->record('application.created', $application, "Application {$application->name} added");
        RepositorySync::afterChange($application, wholeRecord: true);
    }

    public function updated(Application $application): void
    {
        $changes = $this->changes($application);

        if ($changes !== []) {
            $this->record('application.updated', $application, "Application {$application->name} changed: ".implode(', ', array_keys($changes)), $changes);
        }

        RepositorySync::afterChange($application);
    }

    public function deleted(Application $application): void
    {
        $this->record('application.deleted', $application, "Application {$application->name} deleted");
        RepositorySync::afterChange($application, wholeRecord: true);
    }

    public function restored(Application $application): void
    {
        $this->record('application.restored', $application, "Application {$application->name} restored");
        RepositorySync::afterChange($application, wholeRecord: true);
    }
}
