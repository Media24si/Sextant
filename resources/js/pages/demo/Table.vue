<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import DemoTableController from '@/actions/App/Http/Controllers/DemoTableController';
import DataTable from '@/components/app/DataTable.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import type { TableFilterChip, TableProps } from '@/types';

/*
 * Temporary demo of the server-side DataTable (P0.11) over the users table.
 * Removed when the Servers list (P1.10) replaces it.
 */
type Row = App.Data.UserRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: { all: number; admin: number; reader: number };
    }
>();

const page = usePage();

const roleLabels: Record<App.Enums.UserRole, string> = {
    admin: 'Admin',
    reader: 'Reader',
};

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'font-medium' }, row.original.name),
    },
    {
        id: 'role',
        header: 'Role',
        enableSorting: true,
        cell: ({ row }) => roleLabels[row.original.role],
    },
    {
        id: 'created_at',
        header: 'Added',
        enableSorting: true,
        cell: ({ row }) =>
            row.original.created_at
                ? h(RelativeTime, { datetime: row.original.created_at })
                : '—',
    },
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Admins', value: 'admin', count: props.counts.admin },
    { label: 'Readers', value: 'reader', count: props.counts.reader },
]);

function ping(): void {
    router.post(DemoTableController.ping(), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="DataTable demo" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="DataTable demo"
            description="Temporary page: sorting, filtering, search and paging all happen on the server."
        >
            <template v-if="page.props.can.manage" #actions>
                <Button data-test="demo-ping" @click="ping">
                    Admin-only action
                </Button>
            </template>
        </PageHeader>

        <DataTable
            :url="DemoTableController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="role"
            chips-label="Filter by role"
            search-placeholder="Search names"
            empty-title="No users match"
            :row-key="(row) => row.id"
        />
    </div>
</template>
