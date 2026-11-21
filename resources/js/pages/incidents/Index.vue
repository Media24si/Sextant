<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import IncidentController from '@/actions/App/Http/Controllers/IncidentController';
import DataTable from '@/components/app/DataTable.vue';
import IncidentStateBadge from '@/components/app/IncidentStateBadge.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import { resourceHref } from '@/lib/resources';
import type { TableFilterChip, TableProps } from '@/types';

/*
 * Incidents (SPEC §13.6): open, acknowledged and resolved, newest first.
 * Lists don't poll (§13.7).
 */
type Row = App.Data.Incidents.IncidentRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: {
            all: number;
            open: number;
            acknowledged: number;
            resolved: number;
        };
        servers: App.Data.OptionData[];
        checkers: App.Data.FilterOptionData[];
        severities: string[];
    }
>();

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'title',
        header: 'Incident',
        cell: ({ row }) =>
            h('div', { class: 'flex flex-col gap-1' }, [
                h('span', { class: 'flex flex-wrap items-center gap-2' }, [
                    h(IncidentStateBadge, { incident: row.original }),
                    h(
                        Link,
                        {
                            href: IncidentController.show(row.original.id),
                            class: 'font-medium hover:underline',
                        },
                        () => row.original.title,
                    ),
                ]),
                h('span', { class: 'text-xs text-muted-foreground' }, [
                    row.original.message,
                    row.original.parent_id
                        ? ' · grouped under a server outage'
                        : '',
                    row.original.children_count > 0
                        ? ` · ${row.original.children_count} grouped`
                        : '',
                ]),
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
        id: 'opened_at',
        header: 'Opened',
        enableSorting: true,
        cell: ({ row }) =>
            h(RelativeTime, { datetime: row.original.opened_at }),
    },
    {
        id: 'resolved_at',
        header: 'Resolved',
        enableSorting: true,
        cell: ({ row }) =>
            row.original.resolved_at
                ? h('span', [
                      h(RelativeTime, { datetime: row.original.resolved_at }),
                      h(
                          'span',
                          { class: 'text-muted-foreground' },
                          ` · ${row.original.resolution}`,
                      ),
                  ])
                : h('span', { class: 'text-muted-foreground' }, '—'),
    },
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Open', value: 'open', count: props.counts.open },
    {
        label: 'Acknowledged',
        value: 'acknowledged',
        count: props.counts.acknowledged,
    },
    { label: 'Resolved', value: 'resolved', count: props.counts.resolved },
]);

const selectClasses =
    'h-9 rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const selected = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;

    return value === '' ? null : value;
};
</script>

<template>
    <Head title="Incidents" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Incidents"
            description="Opened after repeated failures, resolved when the check passes again. Site incidents during a server outage are grouped under it."
        />

        <DataTable
            :url="IncidentController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="state"
            chips-label="Filter incidents"
            empty-title="No incidents here"
            :row-key="(row) => row.id"
        >
            <template #filters="{ setFilter }">
                <select
                    :class="selectClasses"
                    aria-label="Severity"
                    :value="filters.severity ?? ''"
                    @change="setFilter('severity', selected($event))"
                >
                    <option value="">Any severity</option>
                    <option
                        v-for="severity in severities"
                        :key="severity"
                        :value="severity"
                    >
                        {{ severity }}
                    </option>
                </select>
                <select
                    :class="selectClasses"
                    aria-label="Resource type"
                    :value="filters.type ?? ''"
                    @change="setFilter('type', selected($event))"
                >
                    <option value="">Servers, domains and sites</option>
                    <option value="server">Servers</option>
                    <option value="domain">Domains</option>
                    <option value="site">Sites</option>
                </select>
                <select
                    :class="selectClasses"
                    aria-label="Server"
                    :value="filters.server ?? ''"
                    @change="setFilter('server', selected($event))"
                >
                    <option value="">Any server</option>
                    <option
                        v-for="server in servers"
                        :key="server.id"
                        :value="String(server.id)"
                    >
                        {{ server.label }}
                    </option>
                </select>
                <select
                    :class="selectClasses"
                    aria-label="Check"
                    :value="filters.checker ?? ''"
                    @change="setFilter('checker', selected($event))"
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
            </template>
        </DataTable>
    </div>
</template>
