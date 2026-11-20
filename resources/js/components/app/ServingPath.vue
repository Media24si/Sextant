<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/*
 * host → [front service, dashed] → [backend server, solid] (SPEC §13.4).
 * External services are dashed because Sextant doesn't run or check them.
 */
const props = defineProps<{
    path: App.Data.ServingPathData;
    hostname?: string;
    backendHref?: NonNullable<InertiaLinkProps['href']>;
}>();

const description = computed(() => {
    const backend = props.path.backend ?? 'no backend assigned';
    const via = props.path.front
        ? ` through ${props.path.front} to ${backend}`
        : ` directly to ${backend}`;

    return `Serving path${props.hostname ? ` of ${props.hostname}` : ''}:${via}`;
});
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5 text-xs">
        <span class="sr-only">{{ description }}</span>
        <template v-if="hostname">
            <span class="font-mono text-foreground" aria-hidden="true">{{
                hostname
            }}</span>
            <span class="text-status-unknown" aria-hidden="true">→</span>
        </template>
        <template v-if="path.front">
            <span
                class="rounded-md border-[1.5px] border-dashed border-status-unknown px-2 py-0.5 text-status-unknown-fg"
                aria-hidden="true"
                >{{ path.front }}</span
            >
            <span class="text-status-unknown" aria-hidden="true">→</span>
        </template>
        <template v-if="path.backend">
            <Link
                v-if="backendHref"
                :href="backendHref"
                class="rounded-md border border-blue-300 bg-blue-50 px-2 py-0.5 font-mono text-blue-900 hover:border-blue-500 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200"
                :aria-label="`Backend server ${path.backend}`"
                >{{ path.backend }}</Link
            >
            <span
                v-else
                class="rounded-md border border-blue-300 bg-blue-50 px-2 py-0.5 font-mono text-blue-900 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200"
                aria-hidden="true"
                >{{ path.backend }}</span
            >
        </template>
        <span
            v-else
            class="rounded-md border border-dashed border-status-unknown px-2 py-0.5 text-muted-foreground"
            aria-hidden="true"
            >backend not assigned</span
        >
    </span>
</template>
