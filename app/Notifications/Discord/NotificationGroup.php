<?php

namespace App\Notifications\Discord;

use App\Models\Concerns\Monitorable;
use App\Models\IncidentEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Events about one root resource and of one kind, sent as one embed
 * (SPEC §12.1 step 4). Kinds: problem (opened, escalated), recovery
 * (resolved), acknowledged, reminder.
 */
final class NotificationGroup
{
    /** @var list<IncidentEvent> */
    public array $events = [];

    public function __construct(
        public readonly string $kind,
        public readonly Model&Monitorable $root,
    ) {}

    public function add(IncidentEvent $event): void
    {
        $this->events[] = $event;
    }
}
