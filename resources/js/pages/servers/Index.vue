<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import DataTable from '@/components/app/DataTable.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import VersionCell from '@/components/app/VersionCell.vue';
import { Button } from '@/components/ui/button';
import type { TableFilterChip, TableProps } from '@/types';

type Row = App.Data.ServerRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: {
            all: number;
            problems: number;
            no_backup: number;
            deleted: number;
        };
    }
>();

const page = usePage();

const mono = (text: string | null) =>
    text
        ? h('span', { class: 'font-mono' }, text)
        : h('span', { class: 'text-muted-foreground' }, '—');

// Version columns stay empty until software versions arrive (Phase 7).
const versionColumn = (
    id: string,
    header: string,
    placeholder = '—',
): ColumnDef<Row, unknown> => ({
    id,
    header,
    cell: () => h(VersionCell, { version: null, placeholder }),
});

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) =>
            row.original.deleted
                ? h('span', { class: 'text-muted-foreground' }, 'Deleted')
                : h(StatusBadge, { status: row.original.status }),
    },
    {
        id: 'name',
        header: 'Server',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', [
                h(
                    Link,
                    {
                        href: ServerController.show(row.original.id),
                        class: 'font-mono font-medium hover:underline',
                    },
                    () => row.original.name,
                ),
                row.original.hostname
                    ? h(
                          'div',
                          { class: 'mt-0.5 text-xs text-muted-foreground' },
                          row.original.hostname,
                      )
                    : null,
            ]),
    },
    {
        id: 'primary_ip',
        header: 'Primary IP',
        cell: ({ row }) => mono(row.original.primary_ip),
    },
    {
        id: 'os',
        header: 'OS',
        cell: ({ row }) => row.original.os ?? '—',
    },
    {
        id: 'sites_count',
        header: 'Sites',
        enableSorting: true,
        cell: ({ row }) => row.original.sites_count,
    },
    {
        id: 'backups',
        header: 'Backups',
        cell: ({ row }) =>
            row.original.backup_locations.length > 0
                ? h(
                      'span',
                      { class: 'text-xs' },
                      row.original.backup_locations.join(' · '),
                  )
                : h(
                      'span',
                      {
                          class: 'text-xs font-semibold text-orange-700 dark:text-orange-400',
                      },
                      'None set',
                  ),
    },
    versionColumn('kernel', 'Kernel', 'not configured'),
    versionColumn('nginx', 'nginx'),
    versionColumn('php', 'PHP'),
    versionColumn('mysql', 'MySQL'),
    versionColumn('openssl', 'OpenSSL'),
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Problems', value: 'problems', count: props.counts.problems },
    {
        label: 'No backup location',
        value: 'no_backup',
        count: props.counts.no_backup,
    },
    ...(props.counts.deleted > 0
        ? [{ label: 'Deleted', value: 'deleted', count: props.counts.deleted }]
        : []),
]);
</script>

<template>
    <Head title="Servers" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Servers"
            :description="`${counts.all} ${counts.all === 1 ? 'server' : 'servers'} · software versions arrive with the versions collector`"
        >
            <template v-if="page.props.can.manage" #actions>
                <Button as-child>
                    <Link :href="ServerController.create()">+ Add server</Link>
                </Button>
            </template>
        </PageHeader>

        <DataTable
            :url="ServerController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="show"
            chips-label="Filter servers"
            empty-title="No servers here"
            :empty-description="
                filters.show
                    ? 'Nothing matches this filter.'
                    : 'Add our servers to start the map.'
            "
            :row-key="(row) => row.id"
        />

        <p class="text-[13px] text-muted-foreground">
            Orange "None set" means no backup location is ticked. Version
            columns fill in once a server's versions endpoint is set up.
        </p>
    </div>
</template>
