<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckerDefaultsCard from '@/components/app/CheckerDefaultsCard.vue';
import PageHeader from '@/components/app/PageHeader.vue';

/*
 * Monitor defaults: per checker, what every monitor inherits. Resolution is
 * config ← these defaults ← the monitor's own overrides (SPEC §4.5).
 */
type Checker = App.Data.Monitors.CheckerDefaultsData;

const props = defineProps<{
    checkers: Checker[];
}>();

const groups = computed(() =>
    [
        { type: 'server', title: 'Servers' },
        { type: 'domain', title: 'Domains' },
        { type: 'site', title: 'Sites' },
        { type: 'repository', title: 'Repositories' },
    ]
        .map((group) => ({
            ...group,
            checkers: props.checkers.filter(
                (checker) => checker.resource_type === group.type,
            ),
        }))
        .filter((group) => group.checkers.length > 0),
);
</script>

<template>
    <Head title="Monitor defaults" />

    <div
        class="flex w-full max-w-[1100px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Monitor defaults"
            description="What every monitor of a kind uses unless the monitor sets its own value. Empty fields keep the built-in default, shown in grey. Changes apply from each monitor's next run."
        />

        <section
            v-for="group in groups"
            :key="group.type"
            class="flex flex-col gap-3"
        >
            <h2 class="text-sm font-semibold text-muted-foreground">
                {{ group.title }}
            </h2>
            <CheckerDefaultsCard
                v-for="checker in group.checkers"
                :key="checker.key"
                :checker="checker"
            />
        </section>
    </div>
</template>
