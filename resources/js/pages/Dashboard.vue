<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import IncidentController from '@/actions/App/Http/Controllers/IncidentController';
import MinimumVersionController from '@/actions/App/Http/Controllers/MinimumVersionController';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import ActivityList from '@/components/app/ActivityList.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import SeverityBadge from '@/components/app/SeverityBadge.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useNow } from '@/composables/useNow';
import { formatDate } from '@/lib/datetime';
import { systemHealth } from '@/lib/health';
import type { Severity } from '@/lib/status';
import { Duration } from '@/lib/duration';

/*
 * Overview (SPEC §13.6, prototype "Overview"): totals, current issues
 * grouped by resource, recently resolved, expiring, recent changes and
 * Sextant's own health. Polls every 10 seconds (§13.7).
 */
// The keys of App.Data.Dashboard.DashboardData, written out: Vue's compiler
// can't read a global type as a whole props object.
const props = defineProps<{
    totals: App.Data.Dashboard.TotalsData;
    issues: App.Data.Dashboard.IssueGroupData[];
    muted: App.Data.Dashboard.IssueGroupData[];
    pending_count: number;
    resolved: App.Data.Dashboard.ResolvedItemData[];
    software: App.Data.Dashboard.SoftwareMinimumRowData[];
    expiring: App.Data.Dashboard.ExpiringItemData[];
    changes: App.Data.ActivityItemData[];
    system: App.Data.Dashboard.SystemHealthData;
}>();

const page = usePage();
const now = useNow();

usePoll(10_000, {
    only: [
        'totals',
        'issues',
        'muted',
        'pending_count',
        'resolved',
        'software',
        'expiring',
        'changes',
        'system',
    ],
});

function acknowledge(id: number): void {
    router.post(
        IncidentController.acknowledge(id).url,
        {},
        {
            preserveScroll: true,
            only: ['issues', 'muted', 'changes'],
        },
    );
}

const totals = computed(() => [
    {
        label: 'Servers',
        counts: props.totals.servers,
        href: ServerController.index(),
        unassigned: 0,
    },
    {
        label: 'Domains',
        counts: props.totals.domains,
        href: DomainController.index(),
        unassigned: 0,
    },
    {
        label: 'Sites (subdomains)',
        counts: props.totals.sites,
        href: SiteController.index(),
        unassigned: props.totals.sites_unassigned,
    },
]);

const statuses: App.Enums.Status[] = ['ok', 'warning', 'error', 'unknown'];

/** "18 OK · 2 warning · 1 error · 1 unknown", as in the prototype. */
const countLabels: Record<App.Enums.Status, string> = {
    ok: 'OK',
    warning: 'warning',
    error: 'error',
    unknown: 'unknown',
};

const health = computed(() => systemHealth(props.system, now.value));

const lastCheck = computed(() =>
    props.system.last_dispatch_at
        ? new Intl.DateTimeFormat('en-GB', {
              timeStyle: 'medium',
              timeZone: 'Europe/Ljubljana',
          }).format(new Date(props.system.last_dispatch_at))
        : null,
);

const since = (iso: string) => Duration.since(iso, now.value);

/** "nginx on web03 · PHP 8.2 on web02", as in the prototype. */
const belowSummary = computed(() => {
    const items = props.software.flatMap((row) =>
        row.below.map((below) => `${row.software} on ${below.server}`),
    );

    return items.length > 0 ? items.join(' · ') : null;
});
</script>

<template>
    <Head title="Overview" />

    <div
        class="flex w-full max-w-[1400px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader title="Overview">
            <template #description>
                Live · updates every 10 seconds<template v-if="lastCheck">
                    · last check {{ lastCheck }}</template
                >
            </template>
            <template v-if="page.props.can.manage" #actions>
                <Button variant="outline" as-child>
                    <Link :href="ServerController.create()">+ Add server</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="DomainController.create()">+ Add domain</Link>
                </Button>
                <Button as-child>
                    <Link :href="SiteController.create()">+ Add site</Link>
                </Button>
            </template>
        </PageHeader>

        <section
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            aria-label="Totals"
        >
            <Link
                v-for="total in totals"
                :key="total.label"
                :href="total.href"
                class="flex flex-col gap-2 rounded-xl border bg-card p-5 hover:border-ring"
            >
                <span class="text-sm text-muted-foreground">{{
                    total.label
                }}</span>
                <span class="text-4xl font-bold">{{ total.counts.total }}</span>
                <span class="flex flex-wrap gap-x-3 gap-y-1 text-sm">
                    <template v-for="status in statuses" :key="status">
                        <span
                            v-if="total.counts[status] > 0"
                            class="inline-flex items-center gap-1.5"
                        >
                            <StatusDot :status="status" />
                            {{ total.counts[status] }}
                            {{ countLabels[status] }}
                        </span>
                    </template>
                </span>
                <span
                    v-if="total.unassigned > 0"
                    class="text-sm text-status-warning-fg"
                    >{{ total.unassigned }} without a backend</span
                >
            </Link>
            <div
                class="flex flex-col gap-2 rounded-xl border p-5"
                :class="
                    props.totals.servers_below_minimum > 0
                        ? 'border-status-warning/40 bg-status-warning-bg/40'
                        : 'bg-card'
                "
            >
                <span
                    class="text-sm"
                    :class="
                        props.totals.servers_below_minimum > 0
                            ? 'text-status-warning-fg'
                            : 'text-muted-foreground'
                    "
                    >Below minimum version</span
                >
                <span
                    class="text-4xl font-bold"
                    :class="{
                        'text-status-warning-fg':
                            props.totals.servers_below_minimum > 0,
                    }"
                >
                    {{ props.totals.servers_below_minimum }}
                    {{
                        props.totals.servers_below_minimum === 1
                            ? 'server'
                            : 'servers'
                    }}
                </span>
                <span
                    class="text-sm"
                    :class="
                        belowSummary
                            ? 'text-status-warning-fg'
                            : 'text-muted-foreground'
                    "
                    >{{
                        belowSummary ??
                        'Every reading is at or above its minimum.'
                    }}</span
                >
            </div>
        </section>

        <div
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,3fr)]"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="issues"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4"
                    >
                        <h2 id="issues" class="text-lg font-semibold">
                            Current issues
                        </h2>
                        <span class="text-sm text-muted-foreground">
                            Worst first
                            <template v-if="pending_count > 0">
                                ·
                                <Link
                                    :href="
                                        MonitorController.index({
                                            query: {
                                                'filter[show]': 'pending',
                                            },
                                        })
                                    "
                                    class="hover:underline"
                                    >{{ pending_count }} pending
                                    confirmation</Link
                                >
                            </template>
                        </span>
                    </div>

                    <p
                        v-if="issues.length === 0"
                        class="flex items-center gap-2 px-5 py-8 text-sm text-muted-foreground"
                    >
                        <StatusDot status="ok" /> All clear. Nothing is failing
                        right now.
                    </p>

                    <ul v-else class="divide-y">
                        <li
                            v-for="group in issues"
                            :key="`${group.resource.type}:${group.resource.id}`"
                            class="flex flex-col gap-3 px-5 py-4"
                        >
                            <div
                                v-for="incident in group.incidents"
                                :key="incident.id"
                                class="flex flex-wrap items-start gap-3"
                            >
                                <SeverityBadge
                                    :severity="incident.severity as Severity"
                                />
                                <div
                                    class="flex min-w-0 flex-1 flex-col gap-0.5"
                                >
                                    <Link
                                        :href="incident.url"
                                        class="font-semibold hover:underline"
                                        >{{ incident.title }}</Link
                                    >
                                    <span class="text-sm text-muted-foreground">
                                        {{ incident.message }} · started
                                        {{
                                            new Intl.DateTimeFormat('en-GB', {
                                                timeStyle: 'short',
                                                timeZone: 'Europe/Ljubljana',
                                            }).format(
                                                new Date(incident.opened_at),
                                            )
                                        }}
                                        · {{ since(incident.opened_at) }}
                                        <template v-if="incident.serving_path">
                                            ·
                                            {{
                                                incident.serving_path
                                            }}</template
                                        >
                                    </span>
                                    <span
                                        v-if="incident.recovering"
                                        class="text-xs font-semibold text-status-ok-fg"
                                        >Recovering</span
                                    >
                                </div>
                                <span
                                    v-if="incident.acknowledged_by"
                                    class="text-sm text-muted-foreground"
                                    >Acknowledged by
                                    {{ incident.acknowledged_by }}</span
                                >
                                <Button
                                    v-else-if="page.props.can.manage"
                                    variant="outline"
                                    size="sm"
                                    @click="acknowledge(incident.id)"
                                    >Acknowledge</Button
                                >
                            </div>

                            <div
                                v-if="group.children.length > 0"
                                class="ml-2 flex flex-col gap-1.5 border-l-2 border-status-error/40 pl-4 text-sm"
                            >
                                <span class="text-muted-foreground">
                                    {{ group.children.length }}
                                    {{
                                        group.children.length === 1
                                            ? 'site'
                                            : 'sites'
                                    }}
                                    behind {{ group.resource.name }}
                                    {{
                                        group.children.length === 1
                                            ? 'is'
                                            : 'are'
                                    }}
                                    failing too:
                                </span>
                                <Link
                                    v-for="child in group.children"
                                    :key="child.id"
                                    :href="child.url"
                                    class="flex flex-wrap items-center gap-x-2 hover:underline"
                                >
                                    <StatusDot :status="child.severity" />
                                    <span class="font-mono">{{
                                        child.title.split(' — ')[0]
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        {{ child.title.split(' — ')[1] }} ·
                                        {{ child.message }}
                                        <template v-if="child.serving_path">
                                            · {{ child.serving_path }}</template
                                        >
                                    </span>
                                </Link>
                            </div>
                        </li>
                    </ul>

                    <details
                        v-if="muted.length > 0"
                        class="border-t px-5 py-3 text-sm"
                    >
                        <summary class="cursor-pointer text-muted-foreground">
                            Muted ({{
                                muted.reduce(
                                    (total, group) =>
                                        total + group.incidents.length,
                                    0,
                                )
                            }})
                        </summary>
                        <ul class="mt-2 flex flex-col gap-1.5">
                            <template
                                v-for="group in muted"
                                :key="`${group.resource.type}:${group.resource.id}`"
                            >
                                <li
                                    v-for="incident in group.incidents"
                                    :key="incident.id"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <StatusDot :status="incident.severity" />
                                    <Link
                                        :href="incident.url"
                                        class="hover:underline"
                                        >{{ incident.title }}</Link
                                    >
                                    <span class="text-muted-foreground">{{
                                        incident.message
                                    }}</span>
                                </li>
                            </template>
                        </ul>
                    </details>
                </section>

                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="resolved"
                >
                    <h2
                        id="resolved"
                        class="border-b px-5 py-4 text-lg font-semibold"
                    >
                        Recently resolved
                    </h2>
                    <p
                        v-if="resolved.length === 0"
                        class="px-5 py-6 text-sm text-muted-foreground"
                    >
                        Nothing resolved in the last 24 hours.
                    </p>
                    <ul v-else class="divide-y text-sm">
                        <li
                            v-for="item in resolved"
                            :key="item.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-5 py-3"
                        >
                            <span class="flex items-center gap-2">
                                <StatusDot status="ok" />
                                <Link
                                    :href="item.url"
                                    class="hover:underline"
                                    >{{ item.title }}</Link
                                >
                                <span
                                    v-if="item.children_count > 0"
                                    class="text-muted-foreground"
                                    >+{{ item.children_count }} sites</span
                                >
                            </span>
                            <span class="text-muted-foreground">
                                down
                                {{
                                    Duration.between(
                                        item.opened_at,
                                        item.resolved_at,
                                    )
                                }}
                                · resolved
                                <RelativeTime :datetime="item.resolved_at" />
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="flex min-w-0 flex-col gap-6">
                <section
                    v-if="software.length > 0"
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="software"
                >
                    <div
                        class="flex items-center justify-between border-b px-5 py-4"
                    >
                        <h2 id="software" class="text-lg font-semibold">
                            Software vs minimum
                        </h2>
                        <Link
                            :href="MinimumVersionController.index()"
                            class="text-sm text-primary hover:underline"
                            >{{
                                page.props.can.manage ? 'Manage' : 'Minimums'
                            }}</Link
                        >
                    </div>
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-5 py-2 font-medium">Software</th>
                                <th class="px-5 py-2 font-medium">Minimum</th>
                                <th class="px-5 py-2 text-right font-medium">
                                    Below
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in software"
                                :key="`${row.software}`"
                            >
                                <td class="px-5 py-2">{{ row.software }}</td>
                                <td class="px-5 py-2">
                                    <span
                                        v-if="row.min_version"
                                        class="font-mono"
                                        >{{ row.min_version }}</span
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >not set</span
                                    >
                                </td>
                                <td class="px-5 py-2 text-right">
                                    <span
                                        v-if="!row.min_version"
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                    <span
                                        v-else-if="row.below.length === 0"
                                        class="text-status-ok-fg"
                                        >0</span
                                    >
                                    <span
                                        v-else
                                        class="font-semibold text-status-warning-fg"
                                        >{{ row.below.length }} ·
                                        {{
                                            row.below
                                                .map((below) => below.server)
                                                .join(', ')
                                        }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="expiring"
                >
                    <h2
                        id="expiring"
                        class="border-b px-5 py-4 text-lg font-semibold"
                    >
                        Expiring in the next 60 days
                    </h2>
                    <p
                        v-if="expiring.length === 0"
                        class="px-5 py-6 text-sm text-muted-foreground"
                    >
                        No certificates or domains expire soon.
                    </p>
                    <ul v-else class="divide-y text-sm">
                        <li
                            v-for="item in expiring"
                            :key="`${item.kind}:${item.name}`"
                            class="flex items-center justify-between gap-3 px-5 py-3"
                        >
                            <Link :href="item.url" class="hover:underline">
                                <span class="font-mono">{{ item.name }}</span> ·
                                {{ item.kind }}
                            </Link>
                            <span
                                :class="
                                    item.days <= 7
                                        ? 'font-semibold text-status-error-fg'
                                        : item.days <= 30
                                          ? 'font-semibold text-status-warning-fg'
                                          : 'text-muted-foreground'
                                "
                                :title="formatDate(item.expires_at)"
                                >{{
                                    item.days < 0
                                        ? 'expired'
                                        : `${item.days} days`
                                }}</span
                            >
                        </li>
                    </ul>
                </section>

                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="changes"
                >
                    <div
                        class="flex items-center justify-between border-b px-5 py-4"
                    >
                        <h2 id="changes" class="text-lg font-semibold">
                            Recent changes
                        </h2>
                        <Link
                            :href="ActivityController.index()"
                            class="text-sm text-primary hover:underline"
                            >All activity</Link
                        >
                    </div>
                    <div class="max-h-[560px] overflow-y-auto px-5">
                        <ActivityList :items="changes" />
                    </div>
                </section>
            </div>
        </div>

        <footer
            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground"
            role="status"
        >
            <span class="inline-flex items-center gap-2"
                ><StatusDot :status="health.status" /> {{ health.label }}</span
            >
            <span v-if="system.last_dispatch_at"
                >Last dispatch
                <RelativeTime :datetime="system.last_dispatch_at"
            /></span>
            <span>{{ pending_count }} pending</span>
            <span v-if="system.stale_monitors > 0"
                >{{ system.stale_monitors }} stale monitors</span
            >
        </footer>
    </div>
</template>
