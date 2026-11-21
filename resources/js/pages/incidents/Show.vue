<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { ref } from 'vue';
import IncidentController from '@/actions/App/Http/Controllers/IncidentController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import IncidentStateBadge from '@/components/app/IncidentStateBadge.vue';
import MonitorDrawer from '@/components/app/MonitorDrawer.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { resourceHref } from '@/lib/resources';

/*
 * Incident page (SPEC §13.6): timeline, grouped incidents, first and last
 * result, links. Admins acknowledge here; there's no manual resolve.
 */
const props = defineProps<{
    detail: App.Data.Incidents.IncidentDetailData;
}>();

const page = usePage();

// Polls like the resource pages (§13.7).
usePoll(10_000, { only: ['detail'] });

const breadcrumbs = [
    { title: 'Incidents', href: IncidentController.index() },
    {
        title: props.detail.incident.title,
        href: IncidentController.show(props.detail.incident.id),
    },
];

const acknowledging = ref(false);
const drawerOpen = ref(false);

function acknowledge(): void {
    router.post(
        IncidentController.acknowledge(props.detail.incident.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (acknowledging.value = true),
            onFinish: () => (acknowledging.value = false),
        },
    );
}

const eventLabels: Record<string, string> = {
    opened: 'Opened',
    escalated: 'Escalated to error',
    deescalated: 'Eased to warning',
    acknowledged: 'Acknowledged',
    reminder: 'Reminder',
    resolved: 'Resolved',
};
</script>

<template>
    <Head :title="detail.incident.title" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-2">
                <h1 class="text-[26px] font-bold tracking-tight">
                    {{ detail.incident.title }}
                </h1>
                <IncidentStateBadge :incident="detail.incident" />
                <p class="text-muted-foreground">
                    {{ detail.incident.message }}
                </p>
                <p
                    class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                >
                    <span
                        >Opened
                        {{ formatDateTime(detail.incident.opened_at) }}</span
                    >
                    <span v-if="detail.incident.resolved_at">
                        Resolved
                        {{ formatDateTime(detail.incident.resolved_at) }} ({{
                            detail.incident.resolution
                        }})
                    </span>
                    <span v-else
                        >Last failure
                        <RelativeTime :datetime="detail.last_seen_at"
                    /></span>
                    <span
                        >{{ detail.occurrences }} failed runs · worst
                        {{ detail.max_severity }}</span
                    >
                    <span v-if="detail.incident.acknowledged_by"
                        >Acknowledged by
                        {{ detail.incident.acknowledged_by }}</span
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="drawerOpen = true">
                    {{ detail.monitor_name }} monitor{{
                        detail.monitor_deleted ? ' (deleted)' : ''
                    }}
                </Button>
                <Button
                    v-if="
                        page.props.can.manage &&
                        detail.incident.state === 'open'
                    "
                    :disabled="acknowledging"
                    @click="acknowledge"
                    >Acknowledge</Button
                >
            </div>
        </header>

        <section
            class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border bg-card p-5 text-sm"
        >
            <span class="text-muted-foreground">On</span>
            <Link
                v-if="resourceHref(detail.incident.resource)"
                :href="resourceHref(detail.incident.resource)!"
                class="font-mono hover:underline"
                >{{ detail.incident.resource.name }}</Link
            >
            <ServingPath
                v-if="detail.serving_path"
                :path="detail.serving_path"
            />
            <span v-if="detail.parent" class="text-muted-foreground">
                Grouped under
                <Link
                    :href="IncidentController.show(detail.parent.id)"
                    class="text-foreground hover:underline"
                    >{{ detail.parent.title }}</Link
                >
            </span>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-[3fr_2fr]">
            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-labelledby="timeline"
            >
                <h2
                    id="timeline"
                    class="border-b px-5 py-4 text-lg font-semibold"
                >
                    Timeline
                </h2>
                <ol class="divide-y text-sm">
                    <li
                        v-for="event in detail.events"
                        :key="event.id"
                        class="flex gap-3 px-5 py-3"
                    >
                        <StatusDot
                            :status="
                                event.type === 'resolved'
                                    ? 'ok'
                                    : event.severity
                            "
                            class="mt-1.5"
                        />
                        <div class="flex min-w-0 flex-1 flex-col">
                            <span class="font-medium">{{
                                eventLabels[event.type] ?? event.type
                            }}</span>
                            <span class="text-muted-foreground">{{
                                event.message
                            }}</span>
                            <span
                                v-if="event.suppressed_reason"
                                class="text-xs text-muted-foreground"
                            >
                                Not sent: {{ event.suppressed_reason }}
                            </span>
                        </div>
                        <span class="shrink-0 text-xs text-muted-foreground">{{
                            formatDateTime(event.created_at)
                        }}</span>
                    </li>
                </ol>
            </section>

            <div class="flex flex-col gap-6">
                <section
                    v-if="detail.children.length > 0"
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="children"
                >
                    <h2
                        id="children"
                        class="border-b px-5 py-4 text-lg font-semibold"
                    >
                        Grouped under this outage ({{ detail.children.length }})
                    </h2>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="child in detail.children"
                            :key="child.id"
                            class="flex flex-col gap-1 px-5 py-3"
                        >
                            <span class="flex flex-wrap items-center gap-2">
                                <IncidentStateBadge :incident="child" />
                                <Link
                                    :href="IncidentController.show(child.id)"
                                    class="font-medium hover:underline"
                                    >{{ child.title }}</Link
                                >
                            </span>
                            <span class="text-muted-foreground">{{
                                child.message
                            }}</span>
                        </li>
                    </ul>
                </section>

                <section
                    v-for="[label, result] in [
                        ['First result', detail.first_result],
                        ['Last result', detail.last_result],
                    ] as const"
                    :key="label"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-5 text-sm"
                >
                    <h2 class="text-lg font-semibold">{{ label }}</h2>
                    <p class="flex items-center gap-2">
                        <StatusDot :status="result.status" label />
                        {{ result.message }}
                    </p>
                    <pre
                        v-if="Object.keys(result.data).length > 0"
                        class="max-h-64 overflow-auto rounded-lg bg-muted/50 p-3 font-mono text-xs"
                        >{{ JSON.stringify(result.data, null, 2) }}</pre>
                </section>
            </div>
        </div>

        <MonitorDrawer
            v-model:open="drawerOpen"
            :monitor-id="detail.monitor_id"
        />
    </div>
</template>
