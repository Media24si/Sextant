<script setup lang="ts">
/* Pending, Recovering, Disabled and Muted markers for a monitor (SPEC §6.1, §6.6). */
defineProps<{
    monitor: App.Data.Monitors.MonitorRowData;
}>();
</script>

<template>
    <span class="inline-flex flex-wrap gap-1.5">
        <span
            v-if="monitor.pending && monitor.enabled"
            class="rounded-full bg-status-warning-bg px-2 py-0.5 text-xs font-semibold text-status-warning-fg"
            title="The latest run failed; it's being confirmed before an incident opens."
            >Pending</span
        >
        <span
            v-if="monitor.recovering"
            class="rounded-full bg-status-ok-bg px-2 py-0.5 text-xs font-semibold text-status-ok-fg"
            title="Passing again; the incident resolves once it's confirmed."
            >Recovering</span
        >
        <span
            v-if="!monitor.enabled"
            class="rounded-full bg-status-unknown-bg px-2 py-0.5 text-xs font-semibold text-status-unknown-fg"
            >Disabled</span
        >
        <span
            v-if="monitor.muted_until"
            class="rounded-full bg-status-unknown-bg px-2 py-0.5 text-xs font-semibold text-status-unknown-fg"
            >Muted</span
        >
    </span>
</template>
