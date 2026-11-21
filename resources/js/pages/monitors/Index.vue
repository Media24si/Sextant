<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h, ref } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import DataTable from '@/components/app/DataTable.vue';
import MonitorBadges from '@/components/app/MonitorBadges.vue';
import MonitorDrawer from '@/components/app/MonitorDrawer.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { everyLabel, monitorDotStatus } from '@/lib/monitors';
import type { TableFilterChip, TableProps } from '@/types';

/*
 * Every monitor on every resource (SPEC §13.6). A row opens the drawer.
 * No polling on lists (§13.7).
 */
type Row = App.Data.Monitors.MonitorRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: {
            all: number;
            pending: number;
            unknown: number;
            disabled: number;
            muted: number;
        };
        checkers: { value: string; label: string }[];
    }
>();

const drawerOpen = ref(false);
const selected = ref<number | null>(null);

function openDrawer(id: number): void {
    selected.value = id;
    drawerOpen.value = true;
}

const resourceHref = (resource: App.Data.Monitors.MonitorResourceData) =>
    ({
        server: ServerController.show,
        domain: DomainController.show,
        site: SiteController.show,
    })[resource.type as 'server' | 'domain' | 'site']?.(resource.id);

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'checker',
        header: 'Monitor',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2.5' }, [
                h(StatusDot, {
                    status: monitorDotStatus(row.original),
                    label: true,
                }),
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'text-left font-medium hover:underline',
                        onClick: () => openDrawer(row.original.id),
                    },
                    row.original.name,
                ),
                h(MonitorBadges, { monitor: row.original }),
            ]),
    },
    {
        id: 'resource',
        header: 'On',
        cell: ({ row }) => {
            const href = resourceHref(row.original.resource);

            return href
                ? h(
                      Link,
                      { href, class: 'font-mono hover:underline' },
                      () => row.original.resource.name,
                  )
                : h('span', { class: 'font-mono' }, row.original.resource.name);
        },
    },
    {
        id: 'message',
        header: 'Latest result',
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: row.original.pending
                        ? 'text-status-warning-fg'
                        : 'text-muted-foreground',
                },
                row.original.message ?? 'Not checked yet',
            ),
    },
    {
        id: 'checked_at',
        header: 'Checked',
        cell: ({ row }) =>
            row.original.checked_at
                ? h(RelativeTime, { datetime: row.original.checked_at })
                : h('span', { class: 'text-muted-foreground' }, '—'),
    },
    {
        id: 'next_run_at',
        header: 'Next run',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'text-muted-foreground' }, [
                row.original.next_run_at
                    ? h(RelativeTime, { datetime: row.original.next_run_at })
                    : '—',
                ` · ${everyLabel(row.original.interval)}`,
            ]),
    },
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Pending', value: 'pending', count: props.counts.pending },
    { label: 'Unknown', value: 'unknown', count: props.counts.unknown },
    { label: 'Disabled', value: 'disabled', count: props.counts.disabled },
    ...(props.counts.muted > 0
        ? [{ label: 'Muted', value: 'muted', count: props.counts.muted }]
        : []),
]);

const selectClasses =
    'h-9 rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const selectedValue = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;

    return value === '' ? null : value;
};
</script>

<template>
    <Head title="Monitors" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Monitors"
            :description="`${counts.all} ${counts.all === 1 ? 'monitor' : 'monitors'} · Pending = the latest run failed and is being confirmed`"
        />

        <DataTable
            :url="MonitorController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="show"
            chips-label="Filter monitors"
            empty-title="No monitors here"
            :row-key="(row) => row.id"
        >
            <template #filters="{ setFilter }">
                <select
                    :class="selectClasses"
                    aria-label="Check"
                    :value="filters.checker ?? ''"
                    @change="setFilter('checker', selectedValue($event))"
                >
                    <option value="">Any check</option>
                    <option
                        v-for="checker in checkers"
                        :key="checker.value"
                        :value="checker.value"
                    >
                        {{ checker.label }}
                    </option>
                </select>
                <select
                    :class="selectClasses"
                    aria-label="Resource type"
                    :value="filters.type ?? ''"
                    @change="setFilter('type', selectedValue($event))"
                >
                    <option value="">Servers, domains and sites</option>
                    <option value="server">Servers</option>
                    <option value="domain">Domains</option>
                    <option value="site">Sites</option>
                </select>
            </template>
        </DataTable>

        <MonitorDrawer v-model:open="drawerOpen" :monitor-id="selected" />
    </div>
</template>
