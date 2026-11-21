<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h } from 'vue';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import SiteImportController from '@/actions/App/Http/Controllers/SiteImportController';
import DataTable from '@/components/app/DataTable.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { TableFilterChip, TableProps } from '@/types';

/* Applications: one web app behind several hostnames (SPEC §3.19, §13.6). */
type Row = App.Data.ApplicationRowData;

const props = defineProps<
    TableProps<Row> & { counts: { all: number; deleted: number } }
>();

const page = usePage();
const canManage = computed(() => page.props.can.manage);

/** The first few hostnames, then "+ N more". */
function hostnames(row: Row): string {
    const shown = row.hostnames.slice(0, 3).join(', ');
    const more = row.hostnames.length - 3;

    return more > 0 ? `${shown} + ${more} more` : shown;
}

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'name',
        header: 'Application',
        enableSorting: true,
        cell: ({ row }) =>
            h('div', [
                h(
                    Link,
                    {
                        href: ApplicationController.show(row.original.id),
                        class: 'font-medium hover:underline',
                    },
                    () => row.original.name,
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
        id: 'hostnames',
        header: 'Hostnames',
        cell: ({ row }) =>
            row.original.hostnames.length > 0
                ? h(
                      'span',
                      { class: 'font-mono text-xs' },
                      hostnames(row.original),
                  )
                : h('span', { class: 'text-muted-foreground' }, 'None yet'),
    },
    {
        id: 'sites_count',
        header: 'Sites',
        enableSorting: true,
        cell: ({ row }) => row.original.sites_count,
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => {
            if (row.original.deleted) {
                return canManage.value
                    ? h(
                          Button,
                          {
                              variant: 'outline',
                              size: 'sm',
                              onClick: () =>
                                  router.post(
                                      ApplicationController.restore(
                                          row.original.id,
                                      ),
                                  ),
                          },
                          () => 'Restore',
                      )
                    : h('span', { class: 'text-muted-foreground' }, 'Deleted');
            }

            return row.original.sites_count > 0
                ? h(StatusBadge, { status: row.original.status })
                : h('span', { class: 'text-muted-foreground' }, '—');
        },
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
    <Head title="Applications" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Applications"
            description="One web app answering on several hostnames, such as a backend shared by svet24.si and reporter.si. Each hostname stays a site with its own checks."
        >
            <template v-if="canManage" #actions>
                <Button variant="outline" as-child>
                    <Link :href="SiteImportController.create()"
                        >Import hostnames</Link
                    >
                </Button>
                <Button as-child>
                    <Link :href="ApplicationController.create()"
                        >+ Add application</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <DataTable
            :url="ApplicationController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="show"
            chips-label="Filter applications"
            search-placeholder="Search applications and hostnames"
            empty-title="No applications yet"
            :row-key="(row) => row.id"
        />
    </div>
</template>
