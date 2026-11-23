<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import ConfidenceBadge from '@/components/app/ConfidenceBadge.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RegistryPreview from '@/components/app/RegistryPreview.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { checkerLabels, monitorArgumentsNote } from '@/lib/labels';

/*
 * Add site (SPEC §10.1, §13.6): paste a URL, review what detection found,
 * change what's wrong, save. Nothing is written until "Add site".
 */
const props = defineProps<{
    url: string | null;
    domain: string | null;
    front_services: App.Data.OptionData[];
    servers: App.Data.OptionData[];
}>();

type Detection = App.Data.Detection.SiteDetectionData;

const detection = ref<Detection | null>(null);
const detector = useHttp<{ url: string }, Detection>({
    url: props.url ?? props.domain ?? '',
});

const form = useForm({
    hostname: '',
    scheme: 'https',
    port: null as number | null,
    path: '/',
    front_service_id: null as number | null,
    server_id: null as number | null,
    description: '',
    github_url: '',
    expected_target: null as string | null,
    add_www: false,
    skip_checkers: [] as string[],
});

const frontChosen = ref(false);
const backendChosen = ref(false);

async function detect(): Promise<void> {
    detection.value = null;

    try {
        const result = await detector.post(SiteController.detect().url);

        detection.value = result;
        frontChosen.value = false;
        backendChosen.value = false;
        form.clearErrors();
        form.hostname = result.hostname;
        form.scheme = result.scheme;
        form.port = result.port;
        form.path = result.path;
        form.front_service_id = result.front?.value?.service_id ?? null;
        form.server_id = result.backend?.value?.server_id ?? null;
        form.expected_target =
            (result.monitors.find(
                (monitor) => monitor.checker === 'site.redirect',
            )?.arguments.expected_target as string | undefined) ?? null;
        form.add_www = false;
        form.skip_checkers = [];
    } catch {
        // 422: the message shows under the URL field.
    }
}

onMounted(() => {
    if (props.url) {
        void detect();
    }
});

const ready = computed(
    () => detection.value !== null && detection.value.duplicate === null,
);

const label = (options: App.Data.OptionData[], value: number | null) =>
    options.find((option) => option.id === value)?.label ?? null;

const servingPath = computed<App.Data.ServingPathData>(() => ({
    front: label(props.front_services, form.front_service_id),
    backend: label(props.servers, form.server_id),
}));

const findings = computed(() => detection.value);

/** Back to this page with the same URL, so detection runs again (SPEC §10.4). */
const returnTo = computed(
    () => SiteController.create({ query: { url: detector.url } }).url,
);

function restore(siteId: number): void {
    router.post(SiteController.restore(siteId).url);
}

function submit(): void {
    form.post(SiteController.store().url, { preserveScroll: true });
}

const siteMonitors = computed(
    () =>
        detection.value?.monitors.filter((monitor) =>
            monitor.checker.startsWith('site.'),
        ) ?? [],
);
const domainMonitors = computed(
    () =>
        detection.value?.monitors.filter((monitor) =>
            monitor.checker.startsWith('domain.'),
        ) ?? [],
);

/** Checks start ticked; unticking one leaves it off the new site or domain. */
function isAttached(checker: string): boolean {
    return !form.skip_checkers.includes(checker);
}

function toggleCheck(checker: string, attached: boolean): void {
    form.skip_checkers = attached
        ? form.skip_checkers.filter((skipped) => skipped !== checker)
        : [...form.skip_checkers, checker];
}

const breadcrumbs = [
    { title: 'Sites', href: SiteController.index() },
    { title: 'Add site', href: SiteController.create() },
];

// Bound from here: vue-tsc misreads a literal "https://" in a template attribute.
const urlPlaceholder = 'https://podjetje.svet24.si';
const githubPlaceholder = 'https://github.com/org/repository';

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';
</script>

<template>
    <Head title="Add site" />

    <div
        class="flex w-full max-w-[1400px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            title="Add site"
            description="Paste the site's URL. Sextant works out the domain, what sits in front and which server builds it. Nothing is saved until you confirm."
        />

        <section
            class="flex flex-col gap-3 rounded-xl border bg-card p-6"
            aria-labelledby="site-url"
        >
            <h2 id="site-url" class="text-lg font-semibold">Site URL</h2>
            <form
                class="flex flex-col gap-3 sm:flex-row"
                novalidate
                @submit.prevent="detect"
            >
                <label for="url" class="sr-only">Site URL</label>
                <Input
                    id="url"
                    v-model="detector.url"
                    class="h-12 flex-1 font-mono text-base"
                    :placeholder="urlPlaceholder"
                    autocomplete="off"
                    autofocus
                    :aria-invalid="!!detector.errors.url"
                    aria-describedby="url-hint"
                />
                <Button
                    type="submit"
                    size="lg"
                    class="h-12 px-6"
                    :disabled="
                        detector.processing || detector.url.trim() === ''
                    "
                >
                    <Spinner v-if="detector.processing" />
                    Detect
                </Button>
            </form>
            <InputError :message="detector.errors.url" />
            <p id="url-hint" class="text-sm text-muted-foreground">
                <template v-if="detector.processing">
                    Looking up DNS, the registry, HTTP and the certificate. This
                    takes up to 15 seconds.
                </template>
                <template v-else>
                    A hostname is enough; Sextant adds https://. A path, such as
                    /novice, is kept for the HTTP check.
                </template>
            </p>
        </section>

        <section
            v-if="findings?.duplicate"
            class="flex flex-col gap-3 rounded-xl border border-status-warning/40 bg-status-warning-bg/40 p-6"
            aria-live="polite"
        >
            <h2 class="text-lg font-semibold">
                <span class="font-mono">{{ findings.display_hostname }}</span>
                {{
                    findings.duplicate.trashed
                        ? 'was deleted'
                        : 'is already a site'
                }}
            </h2>
            <p class="text-sm">
                Its serving path was {{ findings.duplicate.serving_path }}.
                <template v-if="findings.duplicate.trashed">
                    Restore it to keep its history, instead of adding it again.
                </template>
            </p>
            <div class="flex gap-3">
                <Button
                    v-if="findings.duplicate.trashed"
                    @click="restore(findings.duplicate.site_id)"
                >
                    Restore site
                </Button>
                <Button variant="outline" as-child>
                    <Link
                        :href="SiteController.show(findings.duplicate.site_id)"
                    >
                        Open the site
                    </Link>
                </Button>
            </div>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            <form
                v-if="ready && findings"
                class="flex min-w-0 flex-col gap-6"
                novalidate
                @submit.prevent="submit"
            >
                <section
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="found"
                >
                    <h2
                        id="found"
                        class="border-b px-6 py-4 text-lg font-semibold"
                    >
                        What Sextant found
                    </h2>
                    <dl class="divide-y text-sm">
                        <div
                            class="grid gap-2 px-6 py-4 sm:grid-cols-[160px_1fr]"
                        >
                            <dt class="text-muted-foreground">Hostname</dt>
                            <dd class="flex flex-col gap-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-base">{{
                                        findings.display_hostname
                                    }}</span>
                                    <ConfidenceBadge confidence="detected" />
                                </span>
                                <span class="text-muted-foreground">
                                    From the URL. The HTTP check will use the
                                    path
                                    <span class="font-mono">{{
                                        findings.path
                                    }}</span
                                    >.
                                </span>
                                <InputError :message="form.errors.hostname" />
                            </dd>
                        </div>

                        <div
                            v-if="findings.domain"
                            class="grid gap-2 px-6 py-4 sm:grid-cols-[160px_1fr]"
                        >
                            <dt class="text-muted-foreground">Domain</dt>
                            <dd class="flex flex-col gap-2">
                                <span class="flex flex-wrap items-center gap-2">
                                    <Link
                                        v-if="findings.domain.value.existing_id"
                                        :href="
                                            DomainController.show(
                                                findings.domain.value
                                                    .existing_id,
                                            )
                                        "
                                        class="font-mono text-base hover:underline"
                                        >{{
                                            findings.domain.value.display_name
                                        }}</Link
                                    >
                                    <span v-else class="font-mono text-base">{{
                                        findings.domain.value.display_name
                                    }}</span>
                                    <ConfidenceBadge
                                        :confidence="findings.domain.confidence"
                                    />
                                </span>
                                <span class="text-muted-foreground">{{
                                    findings.domain.explanation
                                }}</span>
                                <RegistryPreview
                                    v-if="findings.domain.value.registry"
                                    :registry="findings.domain.value.registry"
                                />
                            </dd>
                        </div>

                        <div
                            v-if="findings.dns"
                            class="grid gap-2 px-6 py-4 sm:grid-cols-[160px_1fr]"
                        >
                            <dt class="text-muted-foreground">DNS</dt>
                            <dd class="flex flex-col gap-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span
                                        v-if="findings.dns.value.ips.length > 0"
                                        class="font-mono text-base break-all"
                                        >{{
                                            findings.dns.value.ips.join(', ')
                                        }}</span
                                    >
                                    <ConfidenceBadge
                                        :confidence="findings.dns.confidence"
                                    />
                                </span>
                                <span class="text-muted-foreground">{{
                                    findings.dns.explanation
                                }}</span>
                            </dd>
                        </div>

                        <div
                            v-if="findings.front"
                            class="grid gap-2 px-6 py-4 sm:grid-cols-[160px_1fr]"
                        >
                            <dt>
                                <label
                                    for="front_service_id"
                                    class="text-muted-foreground"
                                    >In front</label
                                >
                            </dt>
                            <dd class="flex flex-col gap-2">
                                <span class="flex flex-wrap items-center gap-2">
                                    <select
                                        id="front_service_id"
                                        v-model="form.front_service_id"
                                        :class="[fieldClasses, 'max-w-xs']"
                                        @change="frontChosen = true"
                                    >
                                        <option :value="null">
                                            Nothing (straight to our server)
                                        </option>
                                        <option
                                            v-for="service in front_services"
                                            :key="service.id"
                                            :value="service.id"
                                        >
                                            {{ service.label }} (external)
                                        </option>
                                    </select>
                                    <ConfidenceBadge
                                        :confidence="findings.front.confidence"
                                        :chosen="frontChosen"
                                    />
                                </span>
                                <span class="text-muted-foreground">{{
                                    findings.front.explanation
                                }}</span>
                                <InputError
                                    :message="form.errors.front_service_id"
                                />
                                <div
                                    v-if="findings.unknown_ip"
                                    class="flex flex-wrap gap-2 pt-1"
                                >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link
                                            :href="
                                                ServerController.create({
                                                    query: {
                                                        ip: findings.unknown_ip,
                                                        return_to: returnTo,
                                                    },
                                                })
                                            "
                                            >Add {{ findings.unknown_ip }} as a
                                            new server</Link
                                        >
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        as-child
                                    >
                                        <Link
                                            :href="
                                                ExternalServiceController.create(
                                                    {
                                                        query: {
                                                            ip: findings.unknown_ip,
                                                            return_to: returnTo,
                                                        },
                                                    },
                                                )
                                            "
                                            >It's an external service</Link
                                        >
                                    </Button>
                                </div>
                            </dd>
                        </div>

                        <div
                            v-if="findings.backend"
                            class="grid gap-2 px-6 py-4 sm:grid-cols-[160px_1fr]"
                            :class="{
                                'bg-status-warning-bg/30':
                                    findings.backend.confidence !==
                                        'detected' && !backendChosen,
                            }"
                        >
                            <dt>
                                <label
                                    for="server_id"
                                    class="text-muted-foreground"
                                    >Backend server</label
                                >
                            </dt>
                            <dd class="flex flex-col gap-2">
                                <span class="flex flex-wrap items-center gap-2">
                                    <select
                                        id="server_id"
                                        v-model="form.server_id"
                                        :class="[
                                            fieldClasses,
                                            'max-w-xs font-mono',
                                        ]"
                                        @change="backendChosen = true"
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
                                    <ConfidenceBadge
                                        :confidence="
                                            findings.backend.confidence
                                        "
                                        :chosen="backendChosen"
                                    />
                                </span>
                                <span class="text-muted-foreground">{{
                                    findings.backend.explanation
                                }}</span>
                                <span
                                    v-if="
                                        findings.backend_candidates.length > 1
                                    "
                                    class="text-muted-foreground"
                                >
                                    Candidates:
                                    <template
                                        v-for="(
                                            candidate, index
                                        ) in findings.backend_candidates"
                                        :key="candidate.server_id"
                                        ><template v-if="index > 0">, </template
                                        ><button
                                            type="button"
                                            class="font-mono text-foreground underline-offset-2 hover:underline"
                                            @click="
                                                form.server_id =
                                                    candidate.server_id;
                                                backendChosen = true;
                                            "
                                        >
                                            {{ candidate.name }}</button
                                        ><template
                                            v-if="candidate.sibling_count > 0"
                                        >
                                            ({{
                                                candidate.sibling_count
                                            }})</template
                                        ></template
                                    >
                                </span>
                                <InputError :message="form.errors.server_id" />
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    class="flex flex-col gap-4 rounded-xl border bg-card p-6"
                    aria-labelledby="path"
                >
                    <h2 id="path" class="text-lg font-semibold">
                        Serving path
                    </h2>
                    <ServingPath
                        class="text-sm"
                        :path="servingPath"
                        :hostname="findings.display_hostname"
                    />
                </section>

                <section
                    v-if="findings.probe"
                    class="overflow-hidden rounded-xl border bg-card"
                    aria-labelledby="probe"
                >
                    <h2
                        id="probe"
                        class="border-b px-6 py-4 text-lg font-semibold"
                    >
                        First probe
                    </h2>
                    <dl class="divide-y text-sm">
                        <div class="flex items-center gap-3 px-6 py-3">
                            <StatusDot
                                :status="findings.probe.http_status"
                                label
                            />
                            <dt class="w-28 font-medium">HTTP</dt>
                            <dd class="min-w-0 break-words">
                                {{ findings.probe.http }}
                            </dd>
                        </div>
                        <div
                            v-if="
                                findings.probe.tls && findings.probe.tls_status
                            "
                            class="flex items-center gap-3 px-6 py-3"
                        >
                            <StatusDot
                                :status="findings.probe.tls_status"
                                label
                            />
                            <dt class="w-28 font-medium">Certificate</dt>
                            <dd class="min-w-0 break-words">
                                {{ findings.probe.tls }}
                            </dd>
                        </div>
                        <div class="flex items-center gap-3 px-6 py-3">
                            <StatusDot
                                :status="findings.probe.redirect_status"
                                label
                            />
                            <dt class="w-28 font-medium">Redirect</dt>
                            <dd class="min-w-0 break-all">
                                {{ findings.probe.redirect }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section
                    v-if="findings.offer_www"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-6"
                >
                    <label class="flex items-start gap-3 text-sm">
                        <input
                            v-model="form.add_www"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span>
                            Also add
                            <span class="font-mono">{{
                                findings.offer_www
                            }}</span>
                            with the same serving path. This URL redirects
                            there, so it's probably the site people see.
                        </span>
                    </label>
                    <InputError :message="form.errors.add_www" />
                </section>

                <section
                    class="flex flex-col gap-5 rounded-xl border bg-card p-6"
                    aria-labelledby="details"
                >
                    <h2 id="details" class="text-lg font-semibold">
                        Details
                        <span class="text-sm font-normal text-muted-foreground"
                            >optional</span
                        >
                    </h2>
                    <FormField
                        label="Description"
                        for="description"
                        :error="form.errors.description"
                    >
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="2"
                            maxlength="500"
                            :class="fieldClasses"
                            placeholder="What is this site? e.g. Regional news section"
                        />
                    </FormField>
                    <FormField
                        label="GitHub repository"
                        for="github_url"
                        :error="form.errors.github_url"
                        description="Shown as a link on the site and in the Domains list."
                    >
                        <Input
                            id="github_url"
                            v-model="form.github_url"
                            class="font-mono"
                            :placeholder="githubPlaceholder"
                            :aria-invalid="!!form.errors.github_url"
                        />
                    </FormField>
                </section>

                <section
                    class="flex flex-col gap-4 rounded-xl border bg-card p-6"
                    aria-labelledby="checks"
                >
                    <div>
                        <h2 id="checks" class="text-lg font-semibold">
                            Checks to attach
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Attached with the site once monitoring is running.
                            Untick any you don't want.
                        </p>
                    </div>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <ul class="flex flex-col gap-2 text-sm">
                            <li
                                v-for="monitor in siteMonitors"
                                :key="monitor.checker"
                                class="flex flex-col"
                            >
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-primary"
                                        :checked="isAttached(monitor.checker)"
                                        @change="
                                            toggleCheck(
                                                monitor.checker,
                                                (
                                                    $event.target as HTMLInputElement
                                                ).checked,
                                            )
                                        "
                                    />
                                    {{
                                        checkerLabels[monitor.checker] ??
                                        monitor.checker
                                    }}
                                </label>
                                <span
                                    v-if="monitorArgumentsNote(monitor)"
                                    class="pl-6 font-mono text-xs break-all text-muted-foreground"
                                    >{{ monitorArgumentsNote(monitor) }}</span
                                >
                            </li>
                        </ul>
                        <div
                            v-if="domainMonitors.length > 0"
                            class="flex flex-col gap-2 text-sm"
                        >
                            <p class="font-medium">
                                For the new domain
                                {{ findings.domain?.value.display_name }}
                            </p>
                            <ul class="flex flex-col gap-2">
                                <li
                                    v-for="monitor in domainMonitors"
                                    :key="monitor.checker"
                                >
                                    <label class="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            class="size-4 accent-primary"
                                            :checked="
                                                isAttached(monitor.checker)
                                            "
                                            @change="
                                                toggleCheck(
                                                    monitor.checker,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                        {{
                                            checkerLabels[monitor.checker] ??
                                            monitor.checker
                                        }}
                                    </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <InputError :message="form.errors.skip_checkers" />
                </section>

                <div class="flex justify-end gap-3">
                    <Button variant="outline" as-child>
                        <Link :href="DomainController.index()">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Add site
                    </Button>
                </div>
            </form>
            <div v-else class="hidden lg:block" />

            <aside
                class="flex flex-col gap-4 rounded-xl border bg-card p-6 text-sm lg:sticky lg:top-6"
                aria-labelledby="how"
            >
                <h2 id="how" class="text-lg font-semibold">
                    How detection works
                </h2>
                <ol class="flex list-decimal flex-col gap-3 pl-5">
                    <li>
                        The URL is cleaned up: lower case, no trailing dot,
                        international names converted.
                    </li>
                    <li>
                        The domain comes from the Public Suffix List, so
                        <span class="font-mono">vestnik.svet24.si</span> belongs
                        to <span class="font-mono">svet24.si</span>. A new
                        domain is created with the site.
                    </li>
                    <li>DNS shows where the hostname points.</li>
                    <li>
                        That address is matched to our servers and to external
                        services such as Cloudflare and Varnish.
                    </li>
                    <li>
                        Behind an external service the backend can't be seen, so
                        Sextant suggests the server the domain's other sites
                        use. You confirm it.
                    </li>
                    <li>
                        A first HTTP and certificate probe runs, and redirects
                        are recorded.
                    </li>
                </ol>
                <p
                    class="rounded-lg bg-blue-50 p-3 text-blue-900 dark:bg-blue-950 dark:text-blue-200"
                >
                    Badges: <strong>Detected</strong> means Sextant is sure,
                    <strong>Suggested</strong> means please confirm,
                    <strong>Not found</strong> means you choose.
                </p>
            </aside>
        </div>
    </div>
</template>
