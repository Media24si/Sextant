<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import ConfidenceBadge from '@/components/app/ConfidenceBadge.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RegistryPreview from '@/components/app/RegistryPreview.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { checkerLabels } from '@/lib/labels';

/*
 * Add domain (SPEC §10.5): look the domain up in the registry, list its
 * subdomains (each prefilled by detection), save everything at once.
 */
const props = defineProps<{
    front_services: App.Data.OptionData[];
    servers: App.Data.OptionData[];
}>();

type Detection = App.Data.Detection.SiteDetectionData;

interface Row {
    key: number;
    hostname: string;
    detecting: boolean;
    detection: Detection | null;
    problem: string | null;
    scheme: string;
    port: number | null;
    path: string;
    front_service_id: number | null;
    server_id: number | null;
    expected_target: string | null;
    frontChosen: boolean;
    backendChosen: boolean;
}

const lookup = useHttp<{ name: string }, App.Data.Detection.DomainLookupData>({
    name: '',
});
const found = ref<App.Data.Detection.DomainLookupData | null>(null);
const rows = reactive<Row[]>([]);
let nextKey = 1;

const isNew = computed(
    () => found.value !== null && found.value.existing_id === null,
);

function newRow(hostname = ''): Row {
    return {
        key: nextKey++,
        hostname,
        detecting: false,
        detection: null,
        problem: null,
        scheme: 'https',
        port: null,
        path: '/',
        front_service_id: null,
        server_id: null,
        expected_target: null,
        frontChosen: false,
        backendChosen: false,
    };
}

async function lookUp(): Promise<void> {
    found.value = null;
    rows.splice(0);

    try {
        found.value = await lookup.post(DomainController.lookup().url);
    } catch {
        return;
    }

    if (found.value.existing_id === null) {
        addRow(found.value.name);
    }
}

// One detection at a time, in the order rows ask for it.
const detector = useHttp<{ url: string }, Detection>({ url: '' });
let queue: Promise<void> = Promise.resolve();

function detectRow(row: Row): void {
    const domain = found.value?.name ?? '';
    let hostname = row.hostname.trim().toLowerCase();

    if (hostname === '') {
        return;
    }

    // "podjetje" means podjetje.svet24.si.
    if (!hostname.includes('.')) {
        hostname = `${hostname}.${domain}`;
        row.hostname = hostname;
    }

    row.detecting = true;
    row.problem = null;
    queue = queue.then(async () => {
        detector.url = hostname;

        try {
            const result = await detector.post(SiteController.detect().url);

            applyDetection(row, result);
        } catch {
            row.detection = null;
            row.problem = detector.errors.url ?? 'Detection failed.';
        } finally {
            row.detecting = false;
        }
    });
}

function applyDetection(row: Row, result: Detection): void {
    row.detection = result;
    row.hostname = result.display_hostname;
    row.scheme = result.scheme;
    row.port = result.port;
    row.path = result.path;
    row.frontChosen = false;
    row.backendChosen = false;

    if (result.duplicate) {
        row.problem = result.duplicate.trashed
            ? 'This hostname was a site and was deleted. Restore it from the Sites list instead.'
            : 'This hostname is already a site.';

        return;
    }

    if (result.domain && result.domain.value.name !== found.value?.name) {
        row.problem = `This hostname belongs to ${result.domain.value.display_name}, not ${found.value?.display_name}.`;
    }

    row.front_service_id = result.front?.value?.service_id ?? null;
    row.server_id = result.backend?.value?.server_id ?? null;
    row.expected_target =
        (result.monitors.find((monitor) => monitor.checker === 'site.redirect')
            ?.arguments.expected_target as string | undefined) ?? null;
}

function addRow(hostname = ''): void {
    const row = newRow(hostname);

    rows.push(row);

    if (hostname !== '') {
        detectRow(rows[rows.length - 1]);
    }
}

function removeRow(key: number): void {
    const index = rows.findIndex((row) => row.key === key);

    if (index !== -1) {
        rows.splice(index, 1);
    }
}

const label = (options: App.Data.OptionData[], value: number | null) =>
    options.find((option) => option.id === value)?.label ?? null;

const pathOf = (row: Row): App.Data.ServingPathData => ({
    front: label(props.front_services, row.front_service_id),
    backend: label(props.servers, row.server_id),
});

/** What detection says about the first step of the path, in a few words. */
function rowNote(row: Row): string | null {
    const front = row.detection?.front;

    if (!front) {
        return null;
    }

    if (front.confidence === 'not_found') {
        return row.detection?.unknown_ip
            ? `Resolves to unknown ${row.detection.unknown_ip}`
            : 'Does not resolve';
    }

    return front.value
        ? `Detected: resolves to ${front.value.name}`
        : 'Detected: straight to our server';
}

const form = useForm({ notes: '' });

function submit(): void {
    if (!found.value) {
        return;
    }

    const name = found.value.name;

    form.transform(() => ({
        name,
        notes: form.notes,
        sites: rows.map((row) => ({
            hostname: row.hostname,
            scheme: row.scheme,
            port: row.port,
            path: row.path,
            front_service_id: row.front_service_id,
            server_id: row.server_id,
            expected_target: row.expected_target,
        })),
    })).post(DomainController.store().url, { preserveScroll: true });
}

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const monitorCount = computed(
    () =>
        2 +
        rows.reduce(
            (total, row) => total + (row.scheme === 'https' ? 4 : 3),
            0,
        ),
);

const busy = computed(() => rows.some((row) => row.detecting));
const blocked = computed(() =>
    rows.some((row) => row.problem !== null || row.hostname.trim() === ''),
);

const breadcrumbs = [
    { title: 'Domains', href: DomainController.index() },
    { title: 'Add domain', href: DomainController.create() },
];

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';
</script>

<template>
    <Head title="Add domain" />

    <div
        class="flex w-full max-w-[1400px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader title="Add domain">
            <template #description>
                Sextant reads the registry, then you describe how each subdomain
                is served. Adding just one site?
                <Link
                    :href="SiteController.create()"
                    class="text-primary underline"
                    >Add site from its URL</Link
                >
                is quicker.
            </template>
        </PageHeader>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            <div class="flex min-w-0 flex-col gap-6">
                <section
                    class="flex flex-col gap-4 rounded-xl border bg-card p-6"
                    aria-labelledby="step-domain"
                >
                    <h2 id="step-domain" class="text-lg font-semibold">
                        <span class="text-muted-foreground">1</span> Domain
                    </h2>
                    <form
                        class="flex flex-col gap-2"
                        novalidate
                        @submit.prevent="lookUp"
                    >
                        <label for="name" class="text-sm font-medium"
                            >Registrable domain</label
                        >
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Input
                                id="name"
                                v-model="lookup.name"
                                class="h-11 flex-1 font-mono text-base"
                                placeholder="svet24.si"
                                autocomplete="off"
                                autofocus
                                :aria-invalid="!!lookup.errors.name"
                            />
                            <Button
                                type="submit"
                                size="lg"
                                class="h-11"
                                :disabled="
                                    lookup.processing ||
                                    lookup.name.trim() === ''
                                "
                            >
                                <Spinner v-if="lookup.processing" />
                                Look up in registry
                            </Button>
                        </div>
                        <InputError :message="lookup.errors.name" />
                        <p class="text-sm text-muted-foreground">
                            Enter the domain you renew, not a subdomain.
                            Subdomains are added in step 2.
                        </p>
                    </form>

                    <div
                        v-if="found && found.existing_id !== null"
                        class="flex flex-wrap items-center gap-3 rounded-lg border border-status-warning/40 bg-status-warning-bg/40 p-4 text-sm"
                    >
                        <span>
                            <span class="font-mono">{{
                                found.display_name
                            }}</span>
                            is already in Sextant.
                        </span>
                        <Button size="sm" variant="outline" as-child>
                            <Link
                                :href="DomainController.show(found.existing_id)"
                                >Open it</Link
                            >
                        </Button>
                        <Button size="sm" as-child>
                            <Link
                                :href="
                                    SiteController.create({
                                        query: { domain: found.existing_id },
                                    })
                                "
                                >Add a site to it</Link
                            >
                        </Button>
                    </div>
                    <RegistryPreview
                        v-else-if="found?.registry"
                        :registry="found.registry"
                    />
                </section>

                <form
                    v-if="isNew && found"
                    class="flex flex-col gap-6"
                    novalidate
                    @submit.prevent="submit"
                >
                    <section
                        class="flex flex-col gap-4 rounded-xl border bg-card p-6"
                        aria-labelledby="step-sites"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <h2 id="step-sites" class="text-lg font-semibold">
                                <span class="text-muted-foreground">2</span>
                                Subdomains and how they're served
                            </h2>
                            <Button
                                type="button"
                                variant="outline"
                                @click="addRow()"
                            >
                                + Add subdomain
                            </Button>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Sextant resolves each hostname and proposes the
                            first step; pick the backend server behind it.
                        </p>
                        <p
                            v-if="rows.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No subdomains: the domain is added on its own, with
                            its expiry and nameserver checks.
                        </p>

                        <div
                            v-for="(row, index) in rows"
                            :key="row.key"
                            class="flex flex-col gap-3 rounded-lg border p-4"
                        >
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="grid gap-1.5">
                                    <label
                                        :for="`hostname-${row.key}`"
                                        class="text-sm font-medium"
                                        >Hostname</label
                                    >
                                    <Input
                                        :id="`hostname-${row.key}`"
                                        v-model="row.hostname"
                                        class="font-mono"
                                        :placeholder="`podjetje.${found.display_name}`"
                                        autocomplete="off"
                                        :aria-invalid="
                                            !!(
                                                row.problem ||
                                                errors[
                                                    `sites.${index}.hostname`
                                                ]
                                            )
                                        "
                                        @change="detectRow(row)"
                                        @keydown.enter.prevent="detectRow(row)"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <label
                                        :for="`front-${row.key}`"
                                        class="text-sm font-medium"
                                        >In front</label
                                    >
                                    <select
                                        :id="`front-${row.key}`"
                                        v-model="row.front_service_id"
                                        :class="fieldClasses"
                                        @change="row.frontChosen = true"
                                    >
                                        <option :value="null">Nothing</option>
                                        <option
                                            v-for="service in front_services"
                                            :key="service.id"
                                            :value="service.id"
                                        >
                                            {{ service.label }} (external)
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-1.5">
                                    <label
                                        :for="`backend-${row.key}`"
                                        class="text-sm font-medium"
                                        >Backend server</label
                                    >
                                    <select
                                        :id="`backend-${row.key}`"
                                        v-model="row.server_id"
                                        :class="fieldClasses"
                                        @change="row.backendChosen = true"
                                    >
                                        <option :value="null">
                                            Not assigned yet
                                        </option>
                                        <option
                                            v-for="server in servers"
                                            :key="server.id"
                                            :value="server.id"
                                        >
                                            {{ server.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <div
                                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"
                            >
                                <span
                                    class="flex items-center gap-2 text-muted-foreground"
                                >
                                    Path
                                    <ServingPath :path="pathOf(row)" />
                                </span>
                                <span
                                    class="ml-auto flex flex-wrap items-center gap-3"
                                >
                                    <span
                                        v-if="row.detecting"
                                        class="flex items-center gap-2 text-muted-foreground"
                                        ><Spinner /> Detecting…</span
                                    >
                                    <template
                                        v-else-if="
                                            row.detection && !row.problem
                                        "
                                    >
                                        <span
                                            :class="
                                                row.detection.front
                                                    ?.confidence === 'detected'
                                                    ? 'text-status-ok-fg'
                                                    : 'text-muted-foreground'
                                            "
                                            >{{ rowNote(row) }}</span
                                        >
                                        <ConfidenceBadge
                                            v-if="row.detection.backend"
                                            :confidence="
                                                row.detection.backend.confidence
                                            "
                                            :chosen="row.backendChosen"
                                            :title="
                                                row.detection.backend
                                                    .explanation
                                            "
                                        />
                                    </template>
                                    <button
                                        type="button"
                                        class="text-destructive hover:underline"
                                        @click="removeRow(row.key)"
                                    >
                                        Remove
                                    </button>
                                </span>
                            </div>
                            <p
                                v-if="
                                    row.detection?.backend &&
                                    !row.problem &&
                                    !row.detecting
                                "
                                class="text-xs text-muted-foreground"
                            >
                                {{ row.detection.backend.explanation }}
                            </p>
                            <InputError
                                :message="
                                    row.problem ??
                                    errors[`sites.${index}.hostname`] ??
                                    errors[`sites.${index}.server_id`] ??
                                    errors[`sites.${index}.front_service_id`]
                                "
                            />
                        </div>
                        <InputError :message="errors.sites" />
                    </section>

                    <section
                        class="flex flex-col gap-4 rounded-xl border bg-card p-6"
                        aria-labelledby="step-checks"
                    >
                        <div>
                            <h2 id="step-checks" class="text-lg font-semibold">
                                <span class="text-muted-foreground">3</span>
                                Checks to attach
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Attached once monitoring is running.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <fieldset class="rounded-lg border p-4 text-sm">
                                <legend class="px-1 font-medium">
                                    For the domain
                                </legend>
                                <ul class="flex flex-col gap-2">
                                    <li
                                        v-for="checker in [
                                            'domain.expiration',
                                            'domain.nameservers',
                                        ]"
                                        :key="checker"
                                        class="flex items-center gap-2"
                                    >
                                        <span
                                            class="text-status-ok"
                                            aria-hidden="true"
                                            >✓</span
                                        >
                                        {{ checkerLabels[checker] }}
                                    </li>
                                </ul>
                            </fieldset>
                            <fieldset class="rounded-lg border p-4 text-sm">
                                <legend class="px-1 font-medium">
                                    For each subdomain
                                </legend>
                                <ul class="flex flex-col gap-2">
                                    <li
                                        v-for="checker in [
                                            'site.http',
                                            'site.ssl',
                                            'site.path',
                                            'site.redirect',
                                        ]"
                                        :key="checker"
                                        class="flex items-center gap-2"
                                    >
                                        <span
                                            class="text-status-ok"
                                            aria-hidden="true"
                                            >✓</span
                                        >
                                        {{ checkerLabels[checker] }}
                                    </li>
                                </ul>
                            </fieldset>
                        </div>
                        <div class="grid gap-1.5">
                            <label for="notes" class="text-sm font-medium"
                                >Notes
                                <span class="font-normal text-muted-foreground"
                                    >optional</span
                                ></label
                            >
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="2"
                                :class="fieldClasses"
                            />
                            <InputError :message="form.errors.notes" />
                        </div>
                    </section>

                    <InputError :message="errors.name" />
                    <div class="flex justify-end gap-3">
                        <Button variant="outline" as-child>
                            <Link :href="DomainController.index()">Cancel</Link>
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing || busy || blocked"
                        >
                            Save domain
                        </Button>
                    </div>
                </form>
            </div>

            <aside
                class="flex flex-col gap-4 rounded-xl border bg-card p-6 text-sm lg:sticky lg:top-6"
                aria-labelledby="summary"
            >
                <h2 id="summary" class="text-lg font-semibold">
                    What will be created
                </h2>
                <dl class="flex flex-col gap-3 border-b pb-4">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Domain</dt>
                        <dd class="font-mono">
                            {{ isNew ? found?.display_name : '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Subdomains</dt>
                        <dd>{{ isNew ? rows.length : 0 }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Monitors</dt>
                        <dd>{{ isNew ? monitorCount : 0 }}</dd>
                    </div>
                </dl>
                <p class="text-muted-foreground">
                    First checks run as soon as monitoring is on. The domain's
                    own status comes from expiry and nameservers; each subdomain
                    gets its own status.
                </p>
                <p
                    class="rounded-lg bg-blue-50 p-3 text-blue-900 dark:bg-blue-950 dark:text-blue-200"
                >
                    Varnish and Cloudflare are external services. Admins manage
                    them under External services, with their IPs, so Sextant can
                    recognise them in DNS.
                </p>
            </aside>
        </div>
    </div>
</template>
