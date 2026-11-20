<script setup lang="ts">
/* Page title, optional description and actions on the right (docs/design). */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[26px] font-bold tracking-tight">{{ title }}</h1>
            <p
                v-if="description || $slots.description"
                class="mt-1.5 text-muted-foreground"
            >
                <slot name="description">{{ description }}</slot>
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
