<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import SiteImportController from '@/actions/App/Http/Controllers/SiteImportController';
import DataTable from '@/components/app/DataTable.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { TableFilterChip, TableProps } from '@/types';

/* Flat list of sites (SPEC §13.6). */
type Row = App.Data.SiteRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: {
            all: number;
            problems: number;
            needs_review: number;
            deleted: number;
        };
        front_services: App.Data.OptionData[];
        servers: App.Data.OptionData[];
        applications: App.Data.OptionData[];
    }
>();

const page = usePage();

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'hostname',
        header: 'Site',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', [
                h(
                    Link,
                    {
                        href: SiteController.show(row.original.id),
                        class: 'font-mono font-medium hover:underline',
                    },
                    () => row.original.hostname,
                ),
                row.original.description
                    ? h(
                          'div',
                          { class: 'mt-0.5 text-xs text-muted-foreground' },
                          row.original.description,
                      )
                    : null,
            ]),
    },
    {
        id: 'domain',
        header: 'Domain',
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: DomainController.show(row.original.domain_id),
                    class: 'font-mono hover:underline',
                },
                () => row.original.domain,
            ),
    },
    {
        id: 'application',
        header: 'Application',
        cell: ({ row }) =>
            row.original.application_id
                ? h(
                      Link,
                      {
                          href: ApplicationController.show(
                              row.original.application_id,
                          ),
                          class: 'hover:underline',
                      },
                      () => row.original.application,
                  )
                : h('span', { class: 'text-muted-foreground' }, '—'),
    },
    {
        id: 'serving_path',
        header: 'Serving path',
        cell: ({ row }) =>
            h(ServingPath, {
                path: row.original.serving_path,
                backendHref: row.original.server_id
                    ? ServerController.show(row.original.server_id)
                    : undefined,
            }),
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) =>
            row.original.deleted
                ? h('span', { class: 'text-muted-foreground' }, 'Deleted')
                : h(StatusBadge, { status: row.original.status }),
    },
    {
        id: 'last_http',
        header: 'Last HTTP',
        // Filled in once the HTTP check runs (Phase 3).
        cell: () => h('span', { class: 'text-muted-foreground' }, '—'),
    },
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Problems', value: 'problems', count: props.counts.problems },
    {
        label: 'Needs review',
        value: 'needs_review',
        count: props.counts.needs_review,
    },
    ...(props.counts.deleted > 0
        ? [{ label: 'Deleted', value: 'deleted', count: props.counts.deleted }]
        : []),
]);

const selectClasses =
    'h-9 rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const selected = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;

    return value === '' ? null : value;
};
</script>

<template>
    <Head title="Sites" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Sites"
            :description="`${counts.all} ${counts.all === 1 ? 'site' : 'sites'} · Needs review = no backend server assigned yet`"
        >
            <template #actions>
                <template v-if="page.props.can.manage">
                    <Button variant="outline" as-child>
                        <Link :href="SiteImportController.create()"
                            >Import</Link
                        >
                    </Button>
                    <Button as-child>
                        <Link :href="SiteController.create()">+ Add site</Link>
                    </Button>
                </template>
            </template>
        </PageHeader>

        <DataTable
            :url="SiteController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="show"
            chips-label="Filter sites"
            search-placeholder="Search hostnames and descriptions"
            empty-title="No sites here"
            :row-key="(row) => row.id"
        >
            <template #filters="{ setFilter }">
                <select
                    :class="selectClasses"
                    aria-label="Front service"
                    :value="filters.front ?? ''"
                    @change="setFilter('front', selected($event))"
                >
                    <option value="">Any front</option>
                    <option value="direct">Direct</option>
                    <option
                        v-for="service in front_services"
                        :key="service.id"
                        :value="String(service.id)"
                    >
                        {{ service.label }}
                    </option>
                </select>
                <select
                    :class="selectClasses"
                    aria-label="Backend server"
                    :value="filters.backend ?? ''"
                    @change="setFilter('backend', selected($event))"
                >
                    <option value="">Any backend</option>
                    <option value="none">Not assigned</option>
                    <option
                        v-for="server in servers"
                        :key="server.id"
                        :value="String(server.id)"
                    >
                        {{ server.label }}
                    </option>
                </select>
                <select
                    v-if="applications.length > 0"
                    :class="selectClasses"
                    aria-label="Application"
                    :value="filters.application ?? ''"
                    @change="setFilter('application', selected($event))"
                >
                    <option value="">Any application</option>
                    <option value="none">No application</option>
                    <option
                        v-for="application in applications"
                        :key="application.id"
                        :value="String(application.id)"
                    >
                        {{ application.label }}
                    </option>
                </select>
            </template>
        </DataTable>
    </div>
</template>
