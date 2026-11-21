<script setup lang="ts">
import SeverityBadge from '@/components/app/SeverityBadge.vue';
import type { Severity } from '@/lib/status';

/*
 * An incident's severity while open, plus Acknowledged / Recovering / Muted
 * markers; "Resolved" once it's over (SPEC §3.11, §6.1).
 */
defineProps<{
    incident: App.Data.Incidents.IncidentRowData;
}>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <template v-if="incident.state !== 'resolved'">
            <SeverityBadge :severity="incident.severity as Severity" />
            <span
                v-if="incident.state === 'acknowledged'"
                class="rounded-full bg-status-unknown-bg px-2 py-0.5 text-xs font-semibold text-status-unknown-fg"
                :title="
                    incident.acknowledged_by
                        ? `Acknowledged by ${incident.acknowledged_by}`
                        : undefined
                "
                >Acknowledged</span
            >
            <span
                v-if="incident.recovering"
                class="rounded-full bg-status-ok-bg px-2 py-0.5 text-xs font-semibold text-status-ok-fg"
                >Recovering</span
            >
            <span
                v-if="incident.muted"
                class="rounded-full bg-status-unknown-bg px-2 py-0.5 text-xs font-semibold text-status-unknown-fg"
                >Muted</span
            >
        </template>
        <span
            v-else
            class="rounded-full bg-status-ok-bg px-2 py-0.5 text-xs font-semibold text-status-ok-fg"
            >Resolved</span
        >
    </span>
</template>
