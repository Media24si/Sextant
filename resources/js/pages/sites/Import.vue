<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import SiteImportController from '@/actions/App/Http/Controllers/SiteImportController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Input } from '@/components/ui/input';
import { parseImportLines } from '@/lib/importLines';

/*
 * Bulk import (SPEC §10.6): paste URLs or an nginx server_name line, Sextant
 * detects each one and adds it. URLs go to the server one at a time, so a long
 * list shows progress. An application and a backend apply to all of them.
 */
type Result = App.Data.Import.ImportResultData;

const props = defineProps<{
    applications: App.Data.OptionData[];
    servers: App.Data.OptionData[];
    application_id: number | null;
}>();

/** An application id, 'new' (type a name) or '' (none). */
const application = ref<string>(
    props.application_id === null ? '' : String(props.application_id),
);
const applicationName = ref('');
const serverId = ref<string>('');

const text = ref('');
const results = ref<Result[]>([]);
const total = ref(0);
const running = ref(false);
const stopping = ref(false);
const importer = useHttp<
    {
        url: string;
        application_id: number | null;
        application_name: string | null;
        server_id: number | null;
    },
    Result
>({ url: '', application_id: null, application_name: null, server_id: null });

const urls = computed(() => parseImportLines(text.value));

const canStart = computed(
    () =>
        urls.value.length > 0 &&
        (application.value !== 'new' || applicationName.value.trim() !== ''),
);

async function start(): Promise<void> {
    const list = urls.value;

    results.value = [];
    total.value = list.length;
    running.value = true;
    stopping.value = false;

    importer.application_id =
        application.value !== '' && application.value !== 'new'
            ? Number(application.value)
            : null;
    importer.application_name =
        application.value === 'new' ? applicationName.value.trim() : null;
    importer.server_id = serverId.value === '' ? null : Number(serverId.value);

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
                message:
                    importer.errors.url ??
                    importer.errors.application_name ??
                    importer.errors.server_id ??
                    'The request failed',
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
    'server_name backend.svet24.si backend.reporter.si;',
].join('\n');

const fieldClasses =
    'h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="Import sites" />

    <div
        class="flex w-full max-w-[1100px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            title="Import sites"
            description="URLs or hostnames, one or more per line. An nginx server_name line works as it is. Sextant detects each one and adds it. Sites whose backend isn't certain get the backend chosen below, or none and are listed under Needs review."
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
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-2">
                    <label for="application" class="text-sm font-medium"
                        >Application</label
                    >
                    <select
                        id="application"
                        v-model="application"
                        :class="fieldClasses"
                        :disabled="running"
                    >
                        <option value="">None</option>
                        <option
                            v-for="option in applications"
                            :key="option.id"
                            :value="String(option.id)"
                        >
                            {{ option.label }}
                        </option>
                        <option value="new">New application…</option>
                    </select>
                    <Input
                        v-if="application === 'new'"
                        v-model="applicationName"
                        aria-label="New application name"
                        placeholder="Media24 backend"
                        :disabled="running"
                    />
                    <p class="text-xs text-muted-foreground">
                        For hostnames of one app. Sites already added join it.
                    </p>
                </div>
                <div class="flex flex-col gap-2">
                    <label for="server_id" class="text-sm font-medium"
                        >Backend server</label
                    >
                    <select
                        id="server_id"
                        v-model="serverId"
                        :class="fieldClasses"
                        :disabled="running"
                    >
                        <option value="">As detected</option>
                        <option
                            v-for="server in servers"
                            :key="server.id"
                            :value="String(server.id)"
                        >
                            {{ server.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Used where detection can't tell, e.g. behind Cloudflare.
                        A detected backend always wins.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Button v-if="!running" :disabled="!canStart" @click="start">
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
