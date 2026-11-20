<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import DataTable from '@/components/app/DataTable.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import type { TableFilterChip, TableProps } from '@/types';

/* The change feed with its filters (SPEC §8, §13.6). */
type Row = App.Data.ActivityItemData;

const props = defineProps<
    TableProps<Row> & {
        categories: App.Enums.ActivityCategory[];
        types: string[];
        servers: App.Data.OptionData[];
        users: App.Data.OptionData[];
    }
>();

const categoryLabels: Record<App.Enums.ActivityCategory, string> = {
    status: 'Status',
    inventory: 'Edit',
    detected: 'Detected',
};

const categoryClasses: Record<App.Enums.ActivityCategory, string> = {
    status: 'bg-status-error-bg text-status-error-fg',
    inventory: 'bg-muted text-muted-foreground',
    detected: 'bg-blue-50 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
};

const resourceLabels: Record<string, string> = {
    server: 'Servers',
    domain: 'Domains',
    site: 'Sites',
    external_service: 'External services',
    user: 'Users',
};

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'created_at',
        header: 'When',
        enableSorting: true,
        cell: ({ row }) =>
            h(RelativeTime, {
                datetime: row.original.created_at,
                class: 'whitespace-nowrap text-muted-foreground',
            }),
    },
    {
        id: 'category',
        header: 'Kind',
        cell: ({ row }) =>
            h(
                'span',
                {
                    class: [
                        'rounded-md px-2 py-0.5 text-xs font-semibold',
                        categoryClasses[row.original.category],
                    ],
                },
                categoryLabels[row.original.category],
            ),
    },
    {
        id: 'summary',
        header: 'Change',
        cell: ({ row }) =>
            row.original.url
                ? h(
                      Link,
                      { href: row.original.url, class: 'hover:underline' },
                      () => row.original.summary,
                  )
                : row.original.summary,
    },
    {
        id: 'user',
        header: 'By',
        cell: ({ row }) =>
            row.original.user ??
            h('span', { class: 'text-muted-foreground' }, 'Sextant'),
    },
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null },
    ...props.categories.map((category) => ({
        label: categoryLabels[category],
        value: category,
    })),
]);

const selectClasses =
    'h-9 max-w-48 rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const selected = (event: Event) => {
    const value = (event.target as HTMLSelectElement).value;

    return value === '' ? null : value;
};
</script>

<template>
    <Head title="Activity" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Activity"
            description="Every change: incidents opening and closing, edits by admins, and what Sextant notices on its own."
        />

        <DataTable
            :url="ActivityController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="category"
            chips-label="Filter by kind"
            empty-title="No changes match"
            :row-key="(row) => row.id"
        >
            <template #filters="{ setFilter }">
                <select
                    :class="selectClasses"
                    aria-label="Type"
                    :value="filters.type ?? ''"
                    @change="setFilter('type', selected($event))"
                >
                    <option value="">Any type</option>
                    <option v-for="type in types" :key="type" :value="type">
                        {{ type }}
                    </option>
                </select>
                <select
                    :class="selectClasses"
                    aria-label="Resource"
                    :value="filters.resource ?? ''"
                    @change="setFilter('resource', selected($event))"
                >
                    <option value="">Any resource</option>
                    <option
                        v-for="(label, value) in resourceLabels"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
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
                    aria-label="By"
                    :value="filters.user ?? ''"
                    @change="setFilter('user', selected($event))"
                >
                    <option value="">Anyone</option>
                    <option value="sextant">Sextant</option>
                    <option
                        v-for="user in users"
                        :key="user.id"
                        :value="String(user.id)"
                    >
                        {{ user.label }}
                    </option>
                </select>
            </template>
        </DataTable>
    </div>
</template>
