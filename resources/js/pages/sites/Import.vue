<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import SiteImportController from '@/actions/App/Http/Controllers/SiteImportController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/*
 * Bulk import (SPEC §10.6): paste URLs, Sextant detects each one and adds it.
 * URLs go to the server one at a time, so a long list shows progress.
 */
type Result = App.Data.Import.ImportResultData;

const text = ref('');
const results = ref<Result[]>([]);
const total = ref(0);
const running = ref(false);
const stopping = ref(false);
const importer = useHttp<{ url: string }, Result>({ url: '' });

const urls = computed(() => [
    ...new Set(
        text.value
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '' && !line.startsWith('#')),
    ),
]);

async function start(): Promise<void> {
    const list = urls.value;

    results.value = [];
    total.value = list.length;
    running.value = true;
    stopping.value = false;

    for (const url of list) {
        if (stopping.value) {
            break;
        }

        importer.url = url;

        try {
            results.value.push(
                await importer.post(SiteImportController.store().url),
            );
        } catch {
            results.value.push({
                input: url,
                hostname: null,
                outcome: 'skipped',
                message: importer.errors.url ?? 'The request failed',
                site_id: null,
            });
        }
    }

    running.value = false;
}

const groups = computed(() =>
    (
        [
            ['created', 'Created'],
            ['needs_review', 'Needs review'],
            ['skipped', 'Skipped'],
        ] as [App.Enums.ImportOutcome, string][]
    ).map(([outcome, title]) => ({
        outcome,
        title,
        items: results.value.filter((result) => result.outcome === outcome),
    })),
);

const groupClasses: Record<App.Enums.ImportOutcome, string> = {
    created: 'text-status-ok-fg',
    needs_review: 'text-status-warning-fg',
    skipped: 'text-muted-foreground',
};

const breadcrumbs = [
    { title: 'Sites', href: SiteController.index() },
    { title: 'Import', href: SiteImportController.create() },
];

const placeholder = [
    'svet24.si',
    'https://podjetje.svet24.si',
    'oglasi24.si/novice',
].join('\n');
</script>

<template>
    <Head title="Import sites" />

    <div
        class="flex w-full max-w-[1100px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            title="Import sites"
            description="One URL per line. Sextant detects each one and adds it. Sites whose backend isn't certain are added without one and listed under Needs review."
        />

        <section class="flex flex-col gap-4 rounded-xl border bg-card p-6">
            <label for="urls" class="text-sm font-medium">URLs</label>
            <textarea
                id="urls"
                v-model="text"
                rows="10"
                class="w-full rounded-md border border-input bg-card px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                :placeholder="placeholder"
                :disabled="running"
            />
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    v-if="!running"
                    :disabled="urls.length === 0"
                    @click="start"
                >
                    Import {{ urls.length }}
                    {{ urls.length === 1 ? 'URL' : 'URLs' }}
                </Button>
                <Button v-else variant="outline" @click="stopping = true">
                    Stop after this one
                </Button>
                <span
                    v-if="running"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    <Spinner />
                    {{ results.length + 1 }} of {{ total }}: {{ importer.url }}
                </span>
                <span
                    v-else-if="results.length > 0"
                    class="text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Done: {{ groups[0].items.length }} created,
                    {{ groups[1].items.length }} need review,
                    {{ groups[2].items.length }} skipped.
                </span>
            </div>
        </section>

        <section
            v-for="group in groups.filter((group) => group.items.length > 0)"
            :key="group.outcome"
            class="overflow-hidden rounded-xl border bg-card"
            :aria-labelledby="`group-${group.outcome}`"
        >
            <h2
                :id="`group-${group.outcome}`"
                class="flex items-center justify-between border-b px-6 py-4 text-lg font-semibold"
            >
                <span :class="groupClasses[group.outcome]">{{
                    group.title
                }}</span>
                <span class="text-sm font-normal text-muted-foreground">{{
                    group.items.length
                }}</span>
            </h2>
            <ul class="divide-y text-sm">
                <li
                    v-for="item in group.items"
                    :key="item.input"
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-6 py-3"
                >
                    <Link
                        v-if="item.site_id"
                        :href="SiteController.show(item.site_id)"
                        class="font-mono hover:underline"
                        >{{ item.hostname }}</Link
                    >
                    <span v-else class="font-mono">{{
                        item.hostname ?? item.input
                    }}</span>
                    <span class="text-muted-foreground">{{
                        item.message
                    }}</span>
                </li>
            </ul>
        </section>

        <p v-if="groups[1].items.length > 0 && !running" class="text-sm">
            <Link
                :href="
                    SiteController.index({
                        query: { 'filter[show]': 'needs_review' },
                    })
                "
                class="text-primary underline"
                >Review the sites without a backend</Link
            >
        </p>
    </div>
</template>
