<script setup lang="ts">
import { computed } from 'vue';

/*
 * A software version in the mono font (SPEC §13.4). Orange and bold when
 * below the minimum an admin set; the distribution package version shows on
 * hover, so an admin can spot a backported fix (GOAL open question).
 */
const props = withDefaults(
    defineProps<{
        version: string | null;
        packageVersion?: string | null;
        belowMinimum?: boolean;
        placeholder?: string;
    }>(),
    {
        packageVersion: null,
        belowMinimum: false,
        placeholder: '—',
    },
);

const title = computed(() => {
    const parts = [];

    if (props.packageVersion) {
        parts.push(`Package ${props.packageVersion}`);
    }

    if (props.belowMinimum) {
        parts.push('Below the minimum version');
    }

    return parts.length > 0 ? parts.join(' · ') : undefined;
});
</script>

<template>
    <span
        v-if="version"
        class="font-mono whitespace-nowrap"
        :class="
            belowMinimum
                ? 'font-semibold text-orange-700 dark:text-orange-400'
                : 'text-foreground'
        "
        :title="title"
    >
        {{ version }}
        <span v-if="belowMinimum" class="sr-only">(below minimum)</span>
    </span>
    <span v-else class="font-mono text-muted-foreground">{{
        placeholder
    }}</span>
</template>
