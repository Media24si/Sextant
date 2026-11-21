<?php

namespace App\Monitoring;

use App\Data\Monitors\CheckerOptionData;
use App\Data\Monitors\MonitorRowData;
use App\Models\Concerns\Monitorable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The monitors section of a server, domain or site page (SPEC §13.6).
 */
class MonitorProps
{
    /**
     * @return array{monitors: list<MonitorRowData>, checker_options: list<CheckerOptionData>}
     */
    public static function for(Model&Monitorable $resource): array
    {
        $presenter = app(MonitorPresenter::class);

        return [
            'monitors' => $presenter->rowsFor($resource),
            // Only admins add monitors; readers don't need the schemas.
            'checker_options' => Gate::allows('manage') ? $presenter->checkerOptions($resource) : [],
        ];
    }
}
