<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

defineProps<{
    title: string;
    description?: string;
    icon?: LucideIcon;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-2 px-6 py-10 text-center text-muted-foreground"
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-8 text-status-unknown"
            aria-hidden="true"
        />
        <p class="font-medium text-foreground">{{ title }}</p>
        <p v-if="description" class="max-w-md text-sm">{{ description }}</p>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
