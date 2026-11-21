<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import IncidentController from '@/actions/App/Http/Controllers/IncidentController';
import IncidentStateBadge from '@/components/app/IncidentStateBadge.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';

/*
 * The Incidents card of a resource page (SPEC §13.6). A server's card also
 * shows its sites' incidents (the `server_id` roll-up).
 */
defineProps<{
    incidents: App.Data.Incidents.IncidentRowData[];
    /** e.g. "Including the sites web02 serves" */
    description?: string;
    /** Query for "All incidents", e.g. { 'filter[server]': '3' } */
    allQuery?: Record<string, string>;
}>();
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border bg-card"
        aria-labelledby="incidents"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
        >
            <div>
                <h2 id="incidents" class="text-lg font-semibold">Incidents</h2>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <Link
                :href="IncidentController.index({ query: allQuery ?? {} })"
                class="text-sm text-muted-foreground hover:underline"
                >All incidents</Link
            >
        </div>

        <p
            v-if="incidents.length === 0"
            class="px-5 py-6 text-sm text-muted-foreground"
        >
            No incidents.
        </p>

        <ul v-else class="divide-y text-sm">
            <li
                v-for="incident in incidents"
                :key="incident.id"
                class="flex flex-col gap-1 px-5 py-3"
            >
                <span class="flex flex-wrap items-center gap-2">
                    <IncidentStateBadge :incident="incident" />
                    <Link
                        :href="IncidentController.show(incident.id)"
                        class="font-medium hover:underline"
                        >{{ incident.title }}</Link
                    >
                </span>
                <span class="text-muted-foreground">
                    {{ incident.message }} ·
                    <template v-if="incident.resolved_at">
                        resolved
                        <RelativeTime :datetime="incident.resolved_at" />
                    </template>
                    <template v-else>
                        opened <RelativeTime :datetime="incident.opened_at" />
                    </template>
                    <template v-if="incident.children_count > 0">
                        · {{ incident.children_count }} grouped
                    </template>
                </span>
            </li>
        </ul>
    </section>
</template>
