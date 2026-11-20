import type { ColumnDef } from '@tanstack/vue-table';
import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import DataTable from '@/components/app/DataTable.vue';
import type { TableRows } from '@/types';

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { get: routerGet },
}));

type Row = { id: number; name: string; role: string };

const columns: ColumnDef<Row, unknown>[] = [
    // A custom cell without an accessor, like most real columns.
    {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) => row.original.name,
    },
    { id: 'role', accessorKey: 'role', header: 'Role', enableSorting: false },
];

const rows = (
    data: Row[],
    meta: Partial<TableRows<Row>['meta']> = {},
): TableRows<Row> => ({
    data,
    meta: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: data.length,
        ...meta,
    },
});

const visitOptions = {
    preserveState: true,
    preserveScroll: true,
    replace: true,
};

function mountTable(props: Record<string, unknown> = {}) {
    return mount(DataTable, {
        props: {
            url: '/demo/table',
            // mount() can't infer DataTable's generic row type from props.
            columns: columns as ColumnDef<unknown, unknown>[],
            rows: rows([
                { id: 1, name: 'Ana', role: 'admin' },
                { id: 2, name: 'Bor', role: 'reader' },
            ]),
            filters: {},
            sort: 'name',
            ...props,
        },
    });
}

describe('DataTable', () => {
    beforeEach(() => {
        routerGet.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the rows it was given', () => {
        const wrapper = mountTable();

        expect(wrapper.findAll('tbody tr').map((tr) => tr.text())).toEqual([
            'Anaadmin',
            'Borreader',
        ]);
        expect(wrapper.find('[data-test="data-table-summary"]').text()).toBe(
            'Showing 1–2 of 2',
        );
    });

    it('sets the chip filter and keeps the sort', async () => {
        const wrapper = mountTable({
            chipFilter: 'role',
            chips: [
                { label: 'All', value: null },
                { label: 'Admins', value: 'admin' },
            ],
        });

        expect(wrapper.find('button[aria-pressed="true"]').text()).toBe('All');

        await wrapper.findAll('button[aria-pressed]')[1].trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/demo/table',
            { filter: { role: 'admin' }, sort: 'name' },
            visitOptions,
        );
    });

    it('clears the chip filter with the All chip and keeps other filters', async () => {
        const wrapper = mountTable({
            filters: { role: 'admin', search: 'an' },
            chipFilter: 'role',
            chips: [
                { label: 'All', value: null },
                { label: 'Admins', value: 'admin' },
            ],
        });

        await wrapper.findAll('button[aria-pressed]')[0].trigger('click');

        expect(routerGet).toHaveBeenCalledWith(
            '/demo/table',
            { filter: { search: 'an' }, sort: 'name' },
            visitOptions,
        );
    });

    it('searches 300 ms after typing stops, from the first page', async () => {
        vi.useFakeTimers();
        const wrapper = mountTable({
            searchPlaceholder: 'Search users',
            rows: rows([], { current_page: 3, last_page: 3, total: 120 }),
        });

        await wrapper.find('[data-test="data-table-search"]').setValue('an');
        await wrapper.find('[data-test="data-table-search"]').setValue('ana');
        vi.advanceTimersByTime(299);
        expect(routerGet).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        await flushPromises();

        expect(routerGet).toHaveBeenCalledOnce();
        expect(routerGet).toHaveBeenCalledWith(
            '/demo/table',
            { filter: { search: 'ana' }, sort: 'name' },
            visitOptions,
        );
    });

    it('toggles a sortable column between ascending and descending', async () => {
        const ascending = mountTable({ sort: 'name' });

        expect(ascending.find('th').attributes('aria-sort')).toBe('ascending');
        expect(ascending.find('[data-test="sort-role"]').exists()).toBe(false);

        await ascending.find('[data-test="sort-name"]').trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/demo/table',
            { sort: '-name' },
            visitOptions,
        );

        const descending = mountTable({ sort: '-name' });
        await descending.find('[data-test="sort-name"]').trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/demo/table',
            { sort: 'name' },
            visitOptions,
        );
    });

    it('pages with the current filters and sort', async () => {
        const wrapper = mountTable({
            filters: { role: 'reader' },
            sort: '-name',
            rows: rows([{ id: 1, name: 'Ana', role: 'reader' }], {
                current_page: 2,
                last_page: 3,
                total: 120,
            }),
        });

        expect(wrapper.find('[data-test="data-table-summary"]').text()).toBe(
            'Showing 51–100 of 120',
        );

        await wrapper.find('[data-test="data-table-next"]').trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/demo/table',
            { filter: { role: 'reader' }, sort: '-name', page: 3 },
            visitOptions,
        );

        await wrapper
            .find('[data-test="data-table-previous"]')
            .trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/demo/table',
            { filter: { role: 'reader' }, sort: '-name' },
            visitOptions,
        );
    });

    it('lets the page set other filters through the filters slot', async () => {
        const wrapper = mount(DataTable, {
            props: {
                url: '/demo/table',
                columns: columns as ColumnDef<unknown, unknown>[],
                rows: rows([]),
                filters: { role: 'admin', backend: '3' },
                sort: 'name',
            },
            slots: {
                filters: `<template #filters="{ setFilter }">
                    <button data-test="pick" @click="setFilter('backend', '7')">7</button>
                    <button data-test="clear" @click="setFilter('backend', null)">clear</button>
                    <button data-test="same" @click="setFilter('backend', '3')">same</button>
                </template>`,
            },
        });

        await wrapper.find('[data-test="pick"]').trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/demo/table',
            { filter: { role: 'admin', backend: '7' }, sort: 'name' },
            visitOptions,
        );

        await wrapper.find('[data-test="clear"]').trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/demo/table',
            { filter: { role: 'admin' }, sort: 'name' },
            visitOptions,
        );

        routerGet.mockClear();
        await wrapper.find('[data-test="same"]').trigger('click');
        expect(routerGet).not.toHaveBeenCalled();
    });

    it('shows the empty state when there are no rows', () => {
        const wrapper = mountTable({
            rows: rows([]),
            emptyTitle: 'No users match',
        });

        expect(wrapper.find('tbody').text()).toBe('No users match');
        expect(wrapper.find('nav').exists()).toBe(false);
    });
});
