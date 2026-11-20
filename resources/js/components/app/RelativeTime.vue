<script setup lang="ts">
import { computed } from 'vue';
import { useNow } from '@/composables/useNow';
import { formatDateTime, formatRelative } from '@/lib/datetime';

/* "7 minutes ago", kept current, with the absolute time as the title (SPEC §13.4). */
const props = defineProps<{
    datetime: string;
}>();

const now = useNow();
const relative = computed(() => formatRelative(props.datetime, now.value));
const absolute = computed(() => formatDateTime(props.datetime));
</script>

<template>
    <time :datetime="datetime" :title="absolute">{{ relative }}</time>
</template>
