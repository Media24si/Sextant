<script setup lang="ts">
import { statusDotClasses, statusLabels } from '@/lib/status';

/*
 * A coloured dot for a status. Decorative by default; pass `label` when the
 * dot is the only place the status shows, so screen readers get it too.
 */
defineProps<{
    status: App.Enums.Status;
    label?: boolean;
}>();
</script>

<template>
    <span
        class="inline-block size-2 shrink-0 rounded-full"
        :class="statusDotClasses[status]"
        :role="label ? 'img' : undefined"
        :aria-label="label ? statusLabels[status] : undefined"
        :aria-hidden="label ? undefined : 'true'"
    />
</template>
