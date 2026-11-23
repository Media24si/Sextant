<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import BlastRadius from '@/components/app/BlastRadius.vue';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { externalServiceKindLabels, rangeSourceLabels } from '@/lib/labels';
import { statusLabels } from '@/lib/status';

/*
 * An external service's page: what it is, where the traffic through it goes,
 * the sites behind it and what goes down with it. Sextant never checks the
 * service itself, so it has no status, monitors or incidents.
 */
const props = defineProps<{
    service: App.Data.ExternalServiceData;
    backends: App.Data.BackendGroupData[];
    sites: App.Data.ServedSiteData[];
    radius: App.Data.BlastRadiusData;
}>();

const page = usePage();

const breadcrumbs = computed(() => [
    { title: 'External services', href: ExternalServiceController.index() },
    {
        title: props.service.name,
        href: ExternalServiceController.show(props.service.id),
    },
]);

const addresses = computed(() => {
    const parts = [...props.service.ranges];

    if (props.service.ranges_source) {
        parts.push(
            `${rangeSourceLabels[props.service.ranges_source]}'s published ranges`,
        );
    }

    return parts;
});

function restore(): void {
    router.post(ExternalServiceController.restore(props.service.id));
}
</script>

<template>
    <Head :title="service.name" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <div
            v-if="service.deleted"
            role="status"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-card px-5 py-4"
        >
            <p>
                This external service was deleted. Its history stays in the
                change feed.
            </p>
            <Button
                v-if="page.props.can.manage"
                variant="outline"
                @click="restore"
            >
                Restore external service
            </Button>
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-[32px] font-bold tracking-tight">
                        {{ service.name }}
                    </h1>
                    <span
                        class="rounded-md border-[1.5px] border-dashed border-status-unknown px-2 py-0.5 text-sm text-status-unknown-fg"
                        >External
                        {{ externalServiceKindLabels[service.kind] }}</span
                    >
                </div>
                <p class="max-w-3xl text-[15px] text-muted-foreground">
                    Sextant doesn't run or check this service. It recognises
                    sites behind it by these addresses, CNAMEs and headers.
                </p>
                <dl
                    class="grid max-w-3xl gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]"
                >
                    <dt class="text-muted-foreground">Addresses</dt>
                    <dd class="font-mono break-words">
                        {{ addresses.length > 0 ? addresses.join(', ') : '—' }}
                    </dd>
                    <template v-if="service.cname_suffixes.length > 0">
                        <dt class="text-muted-foreground">CNAME suffixes</dt>
                        <dd class="font-mono break-words">
                            {{ service.cname_suffixes.join(', ') }}
                        </dd>
                    </template>
                    <template v-if="service.response_headers.length > 0">
                        <dt class="text-muted-foreground">Response headers</dt>
                        <dd class="font-mono break-words">
                            {{ service.response_headers.join(', ') }}
                        </dd>
                    </template>
                </dl>
                <p
                    v-if="service.notes"
                    class="max-w-3xl text-sm whitespace-pre-line"
                >
                    {{ service.notes }}
                </p>
            </div>

            <div
                v-if="page.props.can.manage && !service.deleted"
                class="flex flex-wrap gap-2"
            >
                <Button as-child>
                    <Link :href="ExternalServiceController.edit(service.id)"
                        >Edit external service</Link
                    >
                </Button>
            </div>
        </header>

        <section
            class="rounded-xl border bg-card p-6"
            aria-labelledby="traffic"
        >
            <h2 id="traffic" class="text-lg font-semibold">
                Where traffic through {{ service.name }} goes
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ sites.length }}
                {{ sites.length === 1 ? 'site is' : 'sites are' }} behind
                {{ service.name }}, built on these servers.
            </p>

            <div
                v-if="backends.length > 0"
                class="mt-5 grid items-center gap-5 lg:grid-cols-[1fr_auto_1fr]"
            >
                <div
                    class="rounded-xl border-[1.5px] border-dashed border-status-unknown px-6 py-5"
                >
                    <p class="text-lg font-semibold">{{ service.name }}</p>
                    <p class="mt-1 text-muted-foreground">
                        {{ sites.length }}
                        {{ sites.length === 1 ? 'site' : 'sites' }} in front of
                        our servers
                    </p>
                </div>
                <ArrowRight
                    class="hidden size-8 text-muted-foreground lg:block"
                    aria-hidden="true"
                />
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="group in backends"
                        :key="group.server_id ?? 0"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg px-5 py-3"
                        :class="
                            group.server_id
                                ? 'border border-blue-300 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100'
                                : 'border border-dashed'
                        "
                    >
                        <div>
                            <Link
                                v-if="group.server_id"
                                :href="ServerController.show(group.server_id)"
                                class="font-mono font-semibold hover:underline"
                                >{{ group.server }}</Link
                            >
                            <p v-else class="font-semibold">
                                Backend not assigned
                            </p>
                            <p class="text-sm opacity-80">
                                {{ group.sites_count }}
                                {{ group.sites_count === 1 ? 'site' : 'sites' }}
                            </p>
                        </div>
                        <span class="font-mono text-sm opacity-80"
                            >{{ group.first_site
                            }}<template v-if="group.sites_count > 1">
                                +{{ group.sites_count - 1 }}</template
                            ></span
                        >
                    </li>
                </ul>
            </div>
            <EmptyState
                v-else
                :title="`No sites are behind ${service.name} yet`"
                description="Choose this service as the front when adding or editing a site."
            />
        </section>

        <BlastRadius
            v-if="sites.length > 0"
            :name="service.name"
            :radius="radius"
        />

        <section
            v-if="sites.length > 0"
            class="overflow-hidden rounded-xl border bg-card"
            aria-labelledby="sites-behind"
        >
            <h2 id="sites-behind" class="px-6 pt-5 pb-4 text-lg font-semibold">
                Sites behind {{ service.name }}
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-6 py-3 font-medium">
                                Site
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Application
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Serving path
                            </th>
                            <th scope="col" class="px-6 py-3 font-medium">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="site in sites"
                            :key="site.id"
                            class="border-t"
                        >
                            <td class="px-6 py-3">
                                <Link
                                    :href="SiteController.show(site.id)"
                                    class="font-mono font-medium hover:underline"
                                    >{{ site.hostname }}</Link
                                >
                                <span
                                    v-if="site.description"
                                    class="block text-xs text-muted-foreground"
                                    >{{ site.description }}</span
                                >
                            </td>
                            <td class="px-3 py-3">
                                <Link
                                    v-if="site.application_id"
                                    :href="
                                        ApplicationController.show(
                                            site.application_id,
                                        )
                                    "
                                    class="hover:underline"
                                    >{{ site.application }}</Link
                                >
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-3 py-3">
                                <ServingPath :path="site.serving_path" />
                            </td>
                            <td class="px-6 py-3">
                                <span class="inline-flex items-center gap-2">
                                    <StatusDot :status="site.status" />
                                    {{ statusLabels[site.status] }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
