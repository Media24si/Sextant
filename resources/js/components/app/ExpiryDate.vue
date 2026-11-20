<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/lib/datetime';

/*
 * "18 Jul 2027 · 241 days", orange within 30 days and red within 7, the
 * domain expiry thresholds (GOAL "Initial checkers").
 */
const props = defineProps<{
    date: string | null;
    days: number | null;
}>();

const tone = computed(() => {
    if (props.days === null) {
        return '';
    }

    if (props.days <= 7) {
        return 'font-semibold text-status-error-fg';
    }

    return props.days <= 30
        ? 'font-semibold text-orange-700 dark:text-orange-400'
        : 'text-muted-foreground';
});

const daysLabel = computed(() => {
    if (props.days === null) {
        return '';
    }

    if (props.days < 0) {
        return 'expired';
    }

    return props.days === 1 ? '1 day' : `${props.days} days`;
});
</script>

<template>
    <span v-if="date" class="whitespace-nowrap"
        >{{ formatDate(date) }} ·
        <span :class="tone">{{ daysLabel }}</span></span
    >
    <span v-else class="text-muted-foreground">—</span>
</template>
