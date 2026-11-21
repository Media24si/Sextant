<?php

namespace App\Notifications\Discord;

use App\Enums\IncidentEventType;
use App\Enums\Status;
use App\Models\Concerns\Monitorable;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Server;
use App\Models\Site;
use App\Support\Duration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One Discord embed per notification group (SPEC §12.1 "Embeds"): title,
 * colour, description with start time and duration, the affected sites with
 * their serving paths for a server outage, and a link to the incident page.
 */
class EmbedBuilder
{
    private const COLOURS = [
        'error' => 0xE53935,
        'warning' => 0xFB8C00,
        'recovered' => 0x43A047,
        'acknowledged' => 0x757575,
    ];

    /** Sites listed in a server embed before "+N more". */
    private const SITES_SHOWN = 10;

    private const TITLE_MAX = 256;

    private const DESCRIPTION_MAX = 4000;

    /**
     * @return array{title: string, description: string, url: string, color: int, footer: array{text: string}, timestamp: string}
     */
    public function build(NotificationGroup $group, CarbonInterface $now): array
    {
        $events = collect($group->events);
        $incidents = $events->map(fn (IncidentEvent $event): Incident => $event->incident)->unique('id')->values();
        $own = $incidents->filter(fn (Incident $incident): bool => $this->isOwn($incident, $group->root))->values();
        $lead = $this->lead($own->isNotEmpty() ? $own : $incidents);
        $children = $incidents->reject(fn (Incident $incident): bool => $this->isOwn($incident, $group->root))->values();

        [$title, $colour, $lines] = match ($group->kind) {
            'problem' => $this->problem($events, $own, $lead, $now),
            'recovery' => $this->recovery($own, $lead),
            'acknowledged' => $this->acknowledged($events, $lead),
            default => $this->reminder($events, $lead, $now),
        };

        if ($group->kind === 'problem' && $group->root instanceof Server) {
            $lines = [...$lines, ...$this->affectedSites($group->root, $lead, $children)];
        }

        if ($lead->checker === 'server.versions') {
            $lines = [...$lines, ...$this->belowMinimum($lead)];
        }

        return [
            'title' => mb_strimwidth($title, 0, self::TITLE_MAX, '…'),
            'description' => mb_strimwidth(implode("\n", $lines), 0, self::DESCRIPTION_MAX, '…'),
            'url' => route('incidents.show', $lead),
            'color' => $colour,
            'footer' => ['text' => 'Sextant'],
            'timestamp' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, IncidentEvent>  $events
     * @param  Collection<int, Incident>  $own
     * @return array{string, int, list<string>}
     */
    private function problem(Collection $events, Collection $own, Incident $lead, CarbonInterface $now): array
    {
        $severity = Status::worst(...$events->map(fn (IncidentEvent $event): Status => $event->severity)->all());
        $others = $own->count() - 1;
        $title = ($severity === Status::Error ? '🔴 ' : '🟠 ').$lead->title.($others > 0 ? " (+{$others} more)" : '');

        $lines = [];

        foreach ($own as $incident) {
            $escalated = $events->contains(fn (IncidentEvent $event): bool => $event->incident_id === $incident->id && $event->type === IncidentEventType::Escalated);
            $lines[] = ($own->count() > 1 ? "**{$incident->title}**: " : '').$incident->message.($escalated ? ' (escalated)' : '');
        }

        $lines[] = "Started {$this->time($lead->opened_at)} · {$this->duration($lead->opened_at, $now)}";

        return [$title, self::COLOURS[$severity === Status::Error ? 'error' : 'warning'], $lines];
    }

    /**
     * @param  Collection<int, Incident>  $own
     * @return array{string, int, list<string>}
     */
    private function recovery(Collection $own, Incident $lead): array
    {
        $resolvedAt = $lead->resolved_at ?? now();
        $title = "✅ Resolved: {$lead->title} ({$this->duration($lead->opened_at, $resolvedAt)})";
        $lines = $own->count() > 1
            ? $own->map(fn (Incident $incident): string => "**{$incident->title}** resolved")->all()
            : ["Was: {$lead->message}"];

        $children = $lead->children()->with('monitorable')->get();

        if ($children->isNotEmpty()) {
            // Counted per site: a site with two failing checks is one site, and it
            // has recovered only when all of its incidents have.
            $sites = $children->groupBy(fn (Incident $child): string => "{$child->monitorable_type}:{$child->monitorable_id}");
            $failing = $sites->filter(fn (Collection $incidents): bool => $incidents->contains(fn (Incident $child): bool => $child->isOpen()));
            $recovered = $sites->count() - $failing->count();
            $line = "{$recovered} of {$sites->count()} sites recovered";

            if ($failing->isNotEmpty()) {
                $line .= '; still failing: '.$failing
                    ->map(function (Collection $incidents): string {
                        $child = $incidents->first();

                        return $child->monitorable?->resourceName() ?? $child->title;
                    })
                    ->join(', ');
            }

            $lines[] = $line;
        }

        return [$title, self::COLOURS['recovered'], array_values($lines)];
    }

    /**
     * @param  Collection<int, IncidentEvent>  $events
     * @return array{string, int, list<string>}
     */
    private function acknowledged(Collection $events, Incident $lead): array
    {
        $user = $events->first()?->user->name ?? 'Someone';

        return ["👀 {$user} acknowledged {$lead->title}", self::COLOURS['acknowledged'], [$lead->message]];
    }

    /**
     * @param  Collection<int, IncidentEvent>  $events
     * @return array{string, int, list<string>}
     */
    private function reminder(Collection $events, Incident $lead, CarbonInterface $now): array
    {
        $title = "⏰ Still failing: {$lead->title} ({$this->duration($lead->opened_at, $now)})";
        $reasons = $events->map(fn (IncidentEvent $event): string => $event->message)->unique()->values()->all();

        return [
            $title,
            self::COLOURS[$lead->severity === Status::Error ? 'error' : 'warning'],
            [...$reasons, $lead->message, "Started {$this->time($lead->opened_at)}"],
        ];
    }

    /**
     * For a server group: the affected sites with their serving paths, up to
     * 10, then "+N more" (§12.1).
     *
     * @param  Collection<int, Incident>  $folded  children folded into this embed
     * @return list<string>
     */
    private function affectedSites(Server $server, Incident $lead, Collection $folded): array
    {
        $children = $lead->children()->open()->with('monitorable')->get()->merge($folded)->unique('id');
        $sites = $children
            ->map(fn (Incident $child): ?Model => $child->monitorable)
            ->filter(fn (?Model $site): bool => $site instanceof Site)
            ->unique(fn (Site $site): int => $site->id)
            ->values();

        if ($sites->isEmpty()) {
            return [];
        }

        $lines = ['', "**Affected sites ({$sites->count()})**"];

        foreach ($sites->take(self::SITES_SHOWN) as $site) {
            /** @var Site $site */
            $site->loadMissing(['frontService', 'server']);
            $path = $site->servingPath();
            $lines[] = "• `{$site->display_hostname}` — ".($path->front !== null ? "{$path->front} → " : '').($path->backend ?? $server->name);
        }

        if ($sites->count() > self::SITES_SHOWN) {
            $lines[] = '+'.($sites->count() - self::SITES_SHOWN).' more';
        }

        return $lines;
    }

    /**
     * `server.versions`: each item below minimum with its CVE link (§12.1).
     *
     * @return list<string>
     */
    private function belowMinimum(Incident $incident): array
    {
        $below = (array) ($incident->last_result['data']['below'] ?? []);
        $lines = [];

        foreach ($below as $item) {
            if (! is_array($item)) {
                continue;
            }

            $line = '• '.($item['software'] ?? '?').' '.($item['version'] ?? '?').' < '.($item['min_version'] ?? '?');
            $lines[] = is_string($item['cve_url'] ?? null) ? "{$line} ([CVE]({$item['cve_url']}))" : $line;
        }

        return $lines;
    }

    /** The incident whose resource is the group's root, preferring a server's TCP outage. */
    private function isOwn(Incident $incident, Model&Monitorable $root): bool
    {
        return $incident->monitorable_type === $root->getMorphClass() && $incident->monitorable_id === $root->getKey();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function lead(Collection $incidents): Incident
    {
        /** @var Incident $lead */
        $lead = $incidents->sortBy([
            fn (Incident $a, Incident $b): int => ($a->checker === 'server.tcp' ? 0 : 1) <=> ($b->checker === 'server.tcp' ? 0 : 1),
            fn (Incident $a, Incident $b): int => $a->opened_at <=> $b->opened_at,
        ])->first();

        return $lead;
    }

    private function time(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone((string) config('sextant.display_timezone'))->format('j M H:i');
    }

    private function duration(CarbonInterface $from, CarbonInterface $to): string
    {
        return Duration::between($from, $to);
    }
}
