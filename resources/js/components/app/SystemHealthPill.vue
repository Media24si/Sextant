<script setup lang="ts">
import { usePage, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { systemHealth } from '@/lib/health';
import { statusToneClasses } from '@/lib/status';

/*
 * Header pill for Sextant's own health (SPEC §13.4): are the checks being
 * dispatched, is the network canary happy, are monitors going stale.
 */
const page = usePage();

// Every page reloads only `system`, so the pill stays current off the Overview.
usePoll(30_000, { only: ['system'] });

const health = computed(() => systemHealth(page.props.system));
</script>

<template>
    <span
        role="status"
        class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[13px] whitespace-nowrap"
        :class="statusToneClasses[health.status]"
    >
        <StatusDot :status="health.status" />
        {{ health.label }}
    </span>
</template>
