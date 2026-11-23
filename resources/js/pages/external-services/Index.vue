<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import DataTable from '@/components/app/DataTable.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { externalServiceKindLabels, rangeSourceLabels } from '@/lib/labels';
import type { TableFilterChip, TableProps } from '@/types';

type Row = App.Data.ExternalServiceRowData;

const props = defineProps<
    TableProps<Row> & { counts: { all: number; deleted: number } }
>();

const page = usePage();
const canManage = computed(() => page.props.can.manage);

function ranges(row: Row): string {
    const parts = [];

    if (row.ranges_count > 0) {
        parts.push(
            `${row.ranges_count} ${row.ranges_count === 1 ? 'address or range' : 'addresses or ranges'}`,
        );
    }

    if (row.ranges_source) {
        parts.push(
            `${rangeSourceLabels[row.ranges_source]}'s published ranges`,
        );
    }

    return parts.length > 0 ? parts.join(' + ') : 'None';
}

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: ExternalServiceController.show(row.original.id),
                    class: 'font-medium hover:underline',
                },
                () => row.original.name,
            ),
    },
    {
        id: 'kind',
        header: 'Kind',
        cell: ({ row }) => externalServiceKindLabels[row.original.kind],
    },
    {
        id: 'ranges',
        header: 'Addresses',
        cell: ({ row }) => ranges(row.original),
    },
    {
        id: 'sites_count',
        header: 'Sites in front of',
        enableSorting: true,
        cell: ({ row }) =>
            row.original.sites_count > 0
                ? h(
                      Link,
                      {
                          href: SiteController.index({
                              query: {
                                  'filter[front]': String(row.original.id),
                              },
                          }),
                          class: 'hover:underline',
                      },
                      () => row.original.sites_count,
                  )
                : 0,
    },
    {
        id: 'actions',
        header: '',
        cell: ({ row }) =>
            canManage.value && row.original.deleted
                ? h(
                      Button,
                      {
                          variant: 'outline',
                          size: 'sm',
                          onClick: () =>
                              router.post(
                                  ExternalServiceController.restore(
                                      row.original.id,
                                  ),
                              ),
                      },
                      () => 'Restore',
                  )
                : null,
    },
];

const chips = computed<TableFilterChip[]>(() =>
    props.counts.deleted > 0
        ? [
              { label: 'All', value: null, count: props.counts.all },
              {
                  label: 'Deleted',
                  value: 'deleted',
                  count: props.counts.deleted,
              },
          ]
        : [],
);
</script>

<template>
    <Head title="External services" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="External services"
            description="Services in front of our servers that we don't run, such as Cloudflare or the Varnish server. Sextant never checks them directly."
        >
            <template v-if="canManage" #actions>
                <Button as-child>
                    <Link :href="ExternalServiceController.create()"
                        >+ Add external service</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <DataTable
            :url="ExternalServiceController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="show"
            chips-label="Filter external services"
            empty-title="No external services"
            :row-key="(row) => row.id"
        />
    </div>
</template>
