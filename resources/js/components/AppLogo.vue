<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppWordmark from '@/components/AppWordmark.vue';
</script>

<template>
    <!--
        Wordmark in the open sidebar, just the sign when it's collapsed to icons.
        The sizes are !important: the sidebar button forces child SVGs to size-4.
    -->
    <AppWordmark
        class="h-7! w-[131px]! text-sidebar-primary-foreground group-data-[collapsible=icon]:hidden"
    />
    <AppLogoIcon
        class="hidden size-8! text-sidebar-primary-foreground group-data-[collapsible=icon]:block"
    />
</template>
