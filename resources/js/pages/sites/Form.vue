<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/*
 * Add / edit site by hand (P1.15). Phase 2's Add site detects the domain and
 * serving path from a URL (SPEC §10).
 */
const props = defineProps<{
    form: App.Data.SiteFormData;
    domains: App.Data.OptionData[];
    front_services: App.Data.OptionData[];
    servers: App.Data.OptionData[];
}>();

const id = props.form.id;

const form = useForm({
    domain_id: props.form.domain_id,
    hostname: props.form.hostname,
    scheme: props.form.scheme,
    // Text in the field; the server turns an empty value into null.
    port: props.form.port === null ? '' : String(props.form.port),
    front_service_id: props.form.front_service_id,
    server_id: props.form.server_id,
    description: props.form.description ?? '',
    github_url: props.form.github_url ?? '',
    notes: props.form.notes ?? '',
});

const title = id === null ? 'Add site' : `Edit ${props.form.hostname}`;

const breadcrumbs = computed(() => [
    { title: 'Sites', href: SiteController.index() },
    id === null
        ? { title: 'Add site', href: SiteController.create() }
        : { title: props.form.hostname, href: SiteController.show(id) },
]);

// Bound from here: vue-tsc misreads a literal "https://" in a template attribute.
const githubPlaceholder = 'https://github.com/media24/svet24';

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';

const label = (options: App.Data.OptionData[], value: number | null) =>
    options.find((option) => option.id === value)?.label ?? null;

const preview = computed<App.Data.ServingPathData>(() => ({
    front: label(props.front_services, form.front_service_id),
    backend: label(props.servers, form.server_id),
}));

const cancelHref = computed(() => {
    if (id !== null) {
        return SiteController.show(id);
    }

    return props.form.domain_id !== null
        ? DomainController.show(props.form.domain_id)
        : SiteController.index();
});

function submit(): void {
    if (id === null) {
        form.post(SiteController.store().url, { preserveScroll: true });
    } else {
        form.put(SiteController.update(id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="title" />

    <div
        class="flex w-full max-w-[880px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            :title="title"
            description="One hostname we serve, with what sits in front of it and the server that builds it."
        />

        <form class="flex flex-col gap-6" novalidate @submit.prevent="submit">
            <section
                class="flex flex-col gap-5 rounded-xl border bg-card p-6"
                aria-labelledby="site"
            >
                <h2 id="site" class="text-lg font-semibold">Site</h2>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <FormField
                        label="Domain"
                        for="domain_id"
                        :error="form.errors.domain_id"
                        required
                    >
                        <select
                            id="domain_id"
                            v-model="form.domain_id"
                            :class="fieldClasses"
                            :aria-invalid="!!form.errors.domain_id"
                        >
                            <option :value="null" disabled>
                                Choose a domain
                            </option>
                            <option
                                v-for="domain in domains"
                                :key="domain.id"
                                :value="domain.id"
                            >
                                {{ domain.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        label="Hostname"
                        for="hostname"
                        :error="form.errors.hostname"
                        description="The domain itself or one of its subdomains."
                        required
                    >
                        <Input
                            id="hostname"
                            v-model="form.hostname"
                            class="font-mono"
                            placeholder="podjetje.svet24.si"
                            autocomplete="off"
                            :aria-invalid="!!form.errors.hostname"
                        />
                    </FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <FormField
                        label="Scheme"
                        for="scheme"
                        :error="form.errors.scheme"
                    >
                        <select
                            id="scheme"
                            v-model="form.scheme"
                            :class="fieldClasses"
                        >
                            <option value="https">https</option>
                            <option value="http">http</option>
                        </select>
                    </FormField>
                    <FormField
                        label="Port"
                        for="port"
                        :error="form.errors.port"
                        description="Empty for the scheme's default."
                    >
                        <Input
                            id="port"
                            v-model="form.port"
                            type="number"
                            min="1"
                            max="65535"
                            :aria-invalid="!!form.errors.port"
                        />
                    </FormField>
                </div>
            </section>

            <section
                class="flex flex-col gap-5 rounded-xl border bg-card p-6"
                aria-labelledby="serving"
            >
                <h2 id="serving" class="text-lg font-semibold">Serving path</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="In front"
                        for="front_service_id"
                        :error="form.errors.front_service_id"
                        description="The external service DNS points at, if any."
                    >
                        <select
                            id="front_service_id"
                            v-model="form.front_service_id"
                            :class="fieldClasses"
                        >
                            <option :value="null">
                                Nothing (DNS points straight at the backend)
                            </option>
                            <option
                                v-for="service in front_services"
                                :key="service.id"
                                :value="service.id"
                            >
                                {{ service.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        label="Backend server"
                        for="server_id"
                        :error="form.errors.server_id"
                        description="The server that builds the page."
                    >
                        <select
                            id="server_id"
                            v-model="form.server_id"
                            :class="fieldClasses"
                        >
                            <option :value="null">Not assigned</option>
                            <option
                                v-for="server in servers"
                                :key="server.id"
                                :value="server.id"
                            >
                                {{ server.label }}
                            </option>
                        </select>
                    </FormField>
                </div>
                <div class="rounded-lg bg-muted px-4 py-3">
                    <p class="mb-2 text-xs text-muted-foreground">Preview</p>
                    <ServingPath
                        :path="preview"
                        :hostname="form.hostname || 'hostname'"
                    />
                </div>
            </section>

            <section
                class="flex flex-col gap-5 rounded-xl border bg-card p-6"
                aria-labelledby="details"
            >
                <h2 id="details" class="text-lg font-semibold">Details</h2>
                <FormField
                    label="Description"
                    for="description"
                    :error="form.errors.description"
                    description="Shown under the hostname and found by search."
                >
                    <Input
                        id="description"
                        v-model="form.description"
                        placeholder="Business section"
                    />
                </FormField>
                <FormField
                    label="GitHub repository"
                    for="github_url"
                    :error="form.errors.github_url"
                >
                    <Input
                        id="github_url"
                        v-model="form.github_url"
                        class="font-mono"
                        :placeholder="githubPlaceholder"
                        :aria-invalid="!!form.errors.github_url"
                    />
                </FormField>
                <FormField label="Notes" for="notes" :error="form.errors.notes">
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        :class="fieldClasses"
                    />
                </FormField>
            </section>

            <div class="flex justify-end gap-3">
                <Button variant="outline" as-child>
                    <Link :href="cancelHref">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing"
                    >Save site</Button
                >
            </div>
        </form>
    </div>
</template>
