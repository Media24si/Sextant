<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center">
        <AppLogoIcon class="size-6 text-sidebar-primary" />
    </div>
    <span
        class="truncate text-[17px] leading-tight font-bold tracking-tight text-sidebar-primary-foreground"
        >{{ name }}</span
    >
</template>
