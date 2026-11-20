<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Overview" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <div>
            <h1 class="text-[26px] font-bold tracking-tight">Overview</h1>
            <p class="mt-1.5 text-muted-foreground">
                Servers, domains and sites, and anything that needs attention.
            </p>
        </div>

        <section
            class="rounded-xl border bg-card px-6 py-10 text-center text-muted-foreground"
        >
            Nothing to show yet. Totals, current issues and recent changes
            appear here once servers, domains and sites are added.
        </section>
    </div>
</template>
