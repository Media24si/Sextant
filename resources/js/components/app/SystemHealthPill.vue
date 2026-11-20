<script setup lang="ts">
import { computed } from 'vue';

/*
 * Header pill for Sextant's own health (SPEC §13.4), e.g. "Checks running ·
 * network OK". Wired to the `system` prop in P6.4; until then it shows Unknown.
 */
const props = withDefaults(
    defineProps<{
        status?: App.Enums.Status;
        label?: string;
    }>(),
    {
        status: 'unknown',
        label: 'Checks not running yet',
    },
);

const tone = computed(
    () =>
        ({
            ok: 'bg-status-ok-bg text-status-ok-fg',
            warning: 'bg-status-warning-bg text-status-warning-fg',
            error: 'bg-status-error-bg text-status-error-fg',
            unknown: 'bg-status-unknown-bg text-status-unknown-fg',
        })[props.status],
);

const dot = computed(
    () =>
        ({
            ok: 'bg-status-ok',
            warning: 'bg-status-warning',
            error: 'bg-status-error',
            unknown: 'bg-status-unknown',
        })[props.status],
);
</script>

<template>
    <span
        role="status"
        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[13px] whitespace-nowrap"
        :class="tone"
    >
        <span class="size-2 rounded-full" :class="dot" aria-hidden="true" />
        {{ label }}
    </span>
</template>
