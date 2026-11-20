<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed } from 'vue';

/*
 * The header search field (SPEC §13.4). Until the Command dialog arrives in
 * P1.19 it is a disabled placeholder that shows where search will live.
 */
const shortcut = computed(() =>
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform)
        ? '⌘ K'
        : 'Ctrl K',
);
</script>

<template>
    <button
        type="button"
        disabled
        title="Search arrives in a later release"
        class="flex h-11 w-full max-w-[580px] min-w-0 items-center gap-2.5 rounded-[10px] border border-input bg-muted pr-3 pl-3.5 text-left text-sm text-muted-foreground disabled:cursor-not-allowed"
    >
        <Search class="size-[18px] shrink-0" aria-hidden="true" />
        <span class="truncate">Search servers, domains, IPs, versions…</span>
        <kbd
            class="ml-auto hidden rounded-md border border-input bg-card px-1.5 py-0.5 font-sans text-xs text-muted-foreground sm:inline"
            >{{ shortcut }}</kbd
        >
    </button>
</template>
