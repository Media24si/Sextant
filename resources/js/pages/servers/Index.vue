<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import CopyText from '@/components/app/CopyText.vue';
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
            below_minimum: number;
            no_backup: number;
            collector_outdated: number;
            deleted: number;
        };
    }
>();

const page = usePage();

const mono = (text: string | null) =>
    text
        ? h('span', { class: 'font-mono' }, text)
        : h('span', { class: 'text-muted-foreground' }, '—');

/*
 * Version columns (SPEC §11.6, §13.6): orange when below the minimum, the
 * package version on hover. PHP lists every branch. "not configured" when the
 * server has no versions endpoint (§11.7).
 */
const versionColumn = (
    software: string,
    header: string,
): ColumnDef<Row, unknown> => ({
    id: software,
    header,
    enableHiding: true,
    cell: ({ row }) => {
        const versions = row.original.versions.filter(
            (version) => version.software === software,
        );

        if (versions.length === 0) {
            return h(VersionCell, {
                version: null,
                placeholder: row.original.versions_configured
                    ? '—'
                    : 'not configured',
            });
        }

        return h(
            'div',
            { class: 'flex flex-col gap-0.5' },
            versions.map((version) =>
                h(VersionCell, {
                    version: version.version,
                    packageVersion: version.package_version,
                    belowMinimum: version.below_minimum,
                }),
            ),
        );
    },
});

/* Version columns are rarely needed day to day; the Columns menu brings them back. */
const hiddenColumns = ['kernel', 'nginx', 'php', 'mysql', 'openssl'];

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
            h(
                Link,
                {
                    href: ServerController.show(row.original.id),
                    class: 'font-mono font-medium hover:underline',
                },
                () => row.original.name,
            ),
    },
    {
        id: 'primary_ip',
        enableHiding: true,
        header: 'Primary IP',
        cell: ({ row }) =>
            row.original.primary_ip
                ? h(CopyText, {
                      text: row.original.primary_ip,
                      class: 'font-mono',
                  })
                : mono(null),
    },
    {
        id: 'os',
        enableHiding: true,
        header: 'OS',
        cell: ({ row }) => row.original.os ?? '—',
    },
    {
        id: 'sites_count',
        header: 'Sites',
        enableSorting: true,
        enableHiding: true,
        cell: ({ row }) => row.original.sites_count,
    },
    {
        id: 'backups',
        enableHiding: true,
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
    versionColumn('kernel', 'Kernel'),
    versionColumn('nginx', 'nginx'),
    versionColumn('php', 'PHP'),
    versionColumn('mysql', 'MySQL'),
    versionColumn('openssl', 'OpenSSL'),
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Problems', value: 'problems', count: props.counts.problems },
    {
        label: 'Below minimum',
        value: 'below_minimum',
        count: props.counts.below_minimum,
    },
    {
        label: 'No backup location',
        value: 'no_backup',
        count: props.counts.no_backup,
    },
    ...(props.counts.collector_outdated > 0
        ? [
              {
                  label: 'Collector outdated',
                  value: 'collector_outdated',
                  count: props.counts.collector_outdated,
              },
          ]
        : []),
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
            :description="`${counts.all} ${counts.all === 1 ? 'server' : 'servers'} · orange versions are below the minimum`"
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
            :hidden-columns="hiddenColumns"
            columns-key="servers"
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
            Orange "None set" means no backup location is ticked. Orange
            versions are below the minimum; hover for the package version, which
            may carry a backported fix.
        </p>
    </div>
</template>
