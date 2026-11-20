<script setup lang="ts">
import StatusDot from '@/components/app/StatusDot.vue';
import { statusToneClasses } from '@/lib/status';

/*
 * Header pill for Sextant's own health (SPEC §13.4), e.g. "Checks running ·
 * network OK". Wired to the `system` prop in P6.4; until then it shows Unknown.
 */
withDefaults(
    defineProps<{
        status?: App.Enums.Status;
        label?: string;
    }>(),
    {
        status: 'unknown',
        label: 'Checks not running yet',
    },
);
</script>

<template>
    <span
        role="status"
        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[13px] whitespace-nowrap"
        :class="statusToneClasses[status]"
    >
        <StatusDot :status="status" />
        {{ label }}
    </span>
</template>
