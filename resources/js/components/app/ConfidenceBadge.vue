<script setup lang="ts">
import { computed } from 'vue';

/*
 * How sure detection is about a finding (SPEC §10.2). `chosen` marks a
 * finding the admin changed, shown as "Chosen by you".
 */
const props = defineProps<{
    confidence: App.Enums.Confidence;
    chosen?: boolean;
}>();

const badge = computed(() => {
    if (props.chosen) {
        return {
            label: 'Chosen by you',
            classes: 'bg-status-ok-bg text-status-ok-fg',
        };
    }

    return {
        detected: {
            label: 'Detected',
            classes: 'bg-status-ok-bg text-status-ok-fg',
        },
        suggested: {
            label: 'Suggested · confirm',
            classes: 'bg-status-warning-bg text-status-warning-fg',
        },
        not_found: {
            label: 'Not found',
            classes: 'bg-status-unknown-bg text-status-unknown-fg',
        },
    }[props.confidence];
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap"
        :class="badge.classes"
    >
        {{ badge.label }}
    </span>
</template>
