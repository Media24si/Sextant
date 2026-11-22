<?php

namespace App\Notifications\Discord;

use App\Enums\IncidentEventType;
use App\Jobs\SendDiscordMessage;
use App\Models\Concerns\Monitorable;
use App\Models\Incident;
use App\Models\IncidentEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Sends pending incident events to Discord (SPEC §12.1 "Flush", steps 1–6):
 * settled events, minus muted monitors and children of an open outage,
 * grouped by root resource and kind, 10 embeds per message.
 */
class NotificationFlusher
{
    public const SUPPRESSED_MUTED = 'muted';

    public const SUPPRESSED_GROUPED = 'grouped';

    /** No webhook configured: marked so the outbox doesn't grow forever. */
    public const SUPPRESSED_NO_WEBHOOK = 'no_webhook';

    public function __construct(private EmbedBuilder $embeds) {}

    /**
     * @return array{sent: int, suppressed: int, waiting: int, messages: int}
     */
    public function flush(CarbonInterface $now): array
    {
        $events = $this->pending($now);
        $eligible = $events->keyBy('id');
        $report = ['sent' => 0, 'suppressed' => 0, 'waiting' => 0, 'messages' => 0];

        /** @var array<string, NotificationGroup> $groups */
        $groups = [];

        foreach ($events as $event) {
            $incident = $event->incident;

            // Step 2: muted monitors send nothing.
            if ($incident->monitor->isMuted()) {
                $this->mark($event, $now, self::SUPPRESSED_MUTED);
                $report['suppressed']++;

                continue;
            }

            // Step 3: children never notify on their own while their parent is open.
            $parent = $incident->parent;

            if ($parent !== null && $this->wasOpenAt($parent, $event->created_at)) {
                $parentOpened = $parent->events->firstWhere('type', IncidentEventType::Opened);

                if ($parentOpened !== null && $parentOpened->notified_at === null) {
                    if ($eligible->has($parentOpened->id)) {
                        // Folded into the parent's embed.
                        $this->group($groups, $event, $parent)->add($event);
                    } else {
                        // The parent's opened event hasn't settled yet: wait for it.
                        $report['waiting']++;
                    }

                    continue;
                }

                $this->mark($event, $now, self::SUPPRESSED_GROUPED);
                $report['suppressed']++;

                continue;
            }

            $this->group($groups, $event, null)->add($event);
        }

        if ($groups === []) {
            return $report;
        }

        $webhook = config('sextant.notifications.discord_webhook_url');
        $embeds = array_map(fn (NotificationGroup $group): array => $this->embeds->build($group, $now), array_values($groups));
        $perMessage = max(1, (int) config('sextant.notifications.max_embeds_per_message'));

        foreach (array_chunk(array_keys($embeds), $perMessage) as $indexes) {
            if (is_string($webhook) && $webhook !== '') {
                SendDiscordMessage::dispatch([
                    'embeds' => array_map(fn (int $index): array => $embeds[$index], $indexes),
                    'allowed_mentions' => ['parse' => []],
                ])->onQueue('notifications');
                $report['messages']++;
            }
        }

        // Step 6.
        $sent = 0;

        foreach ($groups as $group) {
            foreach ($group->events as $event) {
                $this->mark($event, $now, is_string($webhook) && $webhook !== '' ? null : self::SUPPRESSED_NO_WEBHOOK);
                $event->incident->forceFill(['last_notified_at' => $now])->save();
                $sent++;
            }
        }

        $report['sent'] = $sent;

        return $report;
    }

    /**
     * Step 1: events to notify, created at least `settle_seconds` ago.
     *
     * @return Collection<int, IncidentEvent>
     */
    private function pending(CarbonInterface $now): Collection
    {
        return IncidentEvent::query()
            ->where('notify', true)
            ->whereNull('notified_at')
            ->where('created_at', '<=', $now->copy()->subSeconds((int) config('sextant.notifications.settle_seconds')))
            ->with([
                'user',
                'incident.monitor',
                'incident.monitorable',
                'incident.parent.events',
                'incident.parent.monitorable',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Step 4: one group per root resource (the incident's own, or for
     * children the parent's server) and kind.
     *
     * @param  array<string, NotificationGroup>  $groups
     */
    private function group(array &$groups, IncidentEvent $event, ?Incident $parent): NotificationGroup
    {
        /** @var Model&Monitorable $root */
        $root = ($parent ?? $event->incident)->monitorable;
        $kind = match ($event->type) {
            IncidentEventType::Opened, IncidentEventType::Escalated, IncidentEventType::Deescalated => 'problem',
            IncidentEventType::Resolved => 'recovery',
            IncidentEventType::Acknowledged => 'acknowledged',
            IncidentEventType::Reminder => 'reminder',
        };
        $key = "{$root->getMorphClass()}:{$root->getKey()}:{$kind}";

        return $groups[$key] ??= new NotificationGroup($kind, $root);
    }

    /** Open when the event happened; resolving in the same second still counts as open. */
    private function wasOpenAt(Incident $incident, CarbonInterface $at): bool
    {
        return $incident->resolved_at === null || $incident->resolved_at->gte($at);
    }

    private function mark(IncidentEvent $event, CarbonInterface $now, ?string $suppressed): void
    {
        $event->forceFill(['notified_at' => $now, 'suppressed_reason' => $suppressed])->save();
    }
}
