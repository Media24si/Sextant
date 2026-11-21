<script setup lang="ts" generic="TRow">
import type { RequestPayload } from '@inertiajs/core';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, Columns3, Search } from '@lucide/vue';
import type { ColumnDef, VisibilityState } from '@tanstack/vue-table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { useDebounceFn, useLocalStorage } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/app/EmptyState.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { toUrl } from '@/lib/utils';
import type { TableFilterChip, TableRows } from '@/types';

/*
 * The shared server-side table (SPEC §13.5). Sorting, filtering and paging
 * happen on the server through spatie/laravel-query-builder: this component
 * only renders the current page and turns chips, search, column sorts and
 * paging into query-string visits (`filter[key]=…`, `sort=-column`, `page=n`).
 * A column is sortable when its `id` is an allowed sort and `enableSorting`
 * is true. A column can be shown or hidden from the Columns menu when
 * `enableHiding` is true; `hiddenColumns` start hidden, and the viewer's
 * choice is remembered in this browser under `columnsKey`.
 */
const props = withDefaults(
    defineProps<{
        url: NonNullable<InertiaLinkProps['href']>;
        columns: ColumnDef<TRow, unknown>[];
        rows: TableRows<TRow>;
        filters: Record<string, string>;
        sort: string | null;
        /** Chips for one filter, e.g. All / Problems / No backup location. */
        chips?: TableFilterChip[];
        chipFilter?: string;
        chipsLabel?: string;
        /** Placeholder for the search box; omit to hide it. Searches `filter[search]`. */
        searchPlaceholder?: string;
        emptyTitle?: string;
        emptyDescription?: string;
        rowKey?: (row: TRow) => string | number;
        /** Ids of hideable columns that start hidden. */
        hiddenColumns?: string[];
        /** localStorage key that remembers which columns are shown. */
        columnsKey?: string;
    }>(),
    {
        chips: () => [],
        chipFilter: undefined,
        chipsLabel: 'Filter',
        searchPlaceholder: undefined,
        emptyTitle: 'Nothing here',
        emptyDescription: undefined,
        rowKey: undefined,
        hiddenColumns: () => [],
        columnsKey: undefined,
    },
);

/* Column visibility */

const defaultVisibility: VisibilityState = Object.fromEntries(
    props.hiddenColumns.map((id) => [id, false]),
);

const columnVisibility = props.columnsKey
    ? useLocalStorage<VisibilityState>(
          `data-table-columns:${props.columnsKey}`,
          defaultVisibility,
          { mergeDefaults: true },
      )
    : ref<VisibilityState>(defaultVisibility);

const table = useVueTable({
    get data() {
        return props.rows.data;
    },
    get columns() {
        return props.columns;
    },
    state: {
        get columnVisibility() {
            return columnVisibility.value;
        },
    },
    onColumnVisibilityChange: (updater) => {
        columnVisibility.value =
            typeof updater === 'function'
                ? updater(columnVisibility.value)
                : updater;
    },
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row, index) =>
        props.rowKey ? String(props.rowKey(row)) : String(index),
    manualSorting: true,
    manualFiltering: true,
    manualPagination: true,
    enableSortingRemoval: false,
});

type Changes = {
    filter?: Record<string, string | null>;
    sort?: string | null;
    page?: number;
};

function visit(changes: Changes): void {
    const filter: Record<string, string> = {};

    for (const [key, value] of Object.entries({
        ...props.filters,
        ...changes.filter,
    })) {
        if (value !== null && value !== '') {
            filter[key] = value;
        }
    }

    const sort = changes.sort !== undefined ? changes.sort : props.sort;
    const query: RequestPayload = {};

    if (Object.keys(filter).length > 0) {
        query.filter = filter;
    }

    if (sort) {
        query.sort = sort;
    }

    if (changes.page !== undefined && changes.page > 1) {
        query.page = changes.page;
    }

    router.get(toUrl(props.url), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/**
 * Set one filter from the page's own controls (the `filters` slot), e.g. a
 * select of backend servers. null clears it.
 */
function setFilter(key: string, value: string | null): void {
    if ((props.filters[key] ?? null) !== value) {
        visit({ filter: { [key]: value } });
    }
}

/* Chips */

function isChipActive(chip: TableFilterChip): boolean {
    const current = props.chipFilter
        ? props.filters[props.chipFilter]
        : undefined;

    return chip.value === null ? current === undefined : current === chip.value;
}

function pickChip(chip: TableFilterChip): void {
    if (props.chipFilter && !isChipActive(chip)) {
        visit({ filter: { [props.chipFilter]: chip.value } });
    }
}

/* Search */

const search = ref(props.filters.search ?? '');

const searchSoon = useDebounceFn((value: string) => {
    if (value.trim() !== (props.filters.search ?? '')) {
        visit({ filter: { search: value.trim() } });
    }
}, 300);

watch(search, (value) => searchSoon(value));
watch(
    () => props.filters.search,
    (value) => {
        if ((value ?? '') !== search.value.trim()) {
            search.value = value ?? '';
        }
    },
);

/* Sorting */

// Opt-in per column, so columns that render a custom cell (no accessor) can sort too.
function isSortable(column: ColumnDef<TRow, unknown>): boolean {
    return column.enableSorting === true;
}

function sortDirection(columnId: string): 'asc' | 'desc' | null {
    if (props.sort === columnId) {
        return 'asc';
    }

    return props.sort === `-${columnId}` ? 'desc' : null;
}

function toggleSort(columnId: string): void {
    visit({
        sort: sortDirection(columnId) === 'asc' ? `-${columnId}` : columnId,
    });
}

function ariaSort(columnId: string): 'ascending' | 'descending' | undefined {
    const direction = sortDirection(columnId);

    if (direction === null) {
        return undefined;
    }

    return direction === 'asc' ? 'ascending' : 'descending';
}

// Opt-in per column, like sorting: only columns marked `enableHiding: true` appear in the menu.
const hideableColumns = computed(() =>
    table
        .getAllLeafColumns()
        .filter((column) => column.columnDef.enableHiding === true),
);

function columnLabel(columnDef: ColumnDef<TRow, unknown>): string {
    return typeof columnDef.header === 'string'
        ? columnDef.header
        : (columnDef.id ?? '');
}

/* Paging */

const meta = computed(() => props.rows.meta);
const firstShown = computed(() =>
    meta.value.total === 0
        ? 0
        : (meta.value.current_page - 1) * meta.value.per_page + 1,
);
const lastShown = computed(() =>
    Math.min(meta.value.current_page * meta.value.per_page, meta.value.total),
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            v-if="
                chips.length > 0 ||
                searchPlaceholder ||
                $slots.filters ||
                hideableColumns.length > 0
            "
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <div
                v-if="chips.length > 0"
                role="group"
                :aria-label="chipsLabel"
                class="flex flex-wrap gap-2"
            >
                <button
                    v-for="chip in chips"
                    :key="chip.label"
                    type="button"
                    :aria-pressed="isChipActive(chip)"
                    class="h-9 rounded-full border px-3.5 text-[13px] font-medium transition-colors"
                    :class="
                        isChipActive(chip)
                            ? 'border-foreground bg-foreground text-background'
                            : 'border-input bg-card text-foreground hover:bg-accent'
                    "
                    @click="pickChip(chip)"
                >
                    {{ chip.label }}
                    <span
                        v-if="chip.count !== undefined"
                        class="ml-1 opacity-70"
                        >{{ chip.count }}</span
                    >
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <slot name="filters" :set-filter="setFilter" />
                <div v-if="searchPlaceholder" class="relative w-full sm:w-72">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="searchPlaceholder"
                        :aria-label="searchPlaceholder"
                        class="bg-card pl-9"
                        data-test="data-table-search"
                    />
                </div>
                <DropdownMenu v-if="hideableColumns.length > 0">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            class="bg-card"
                            data-test="data-table-columns"
                        >
                            <Columns3 class="size-4" aria-hidden="true" />
                            Columns
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-44">
                        <DropdownMenuLabel>Show columns</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuCheckboxItem
                            v-for="column in hideableColumns"
                            :key="column.id"
                            :model-value="column.getIsVisible()"
                            @update:model-value="
                                (visible) => column.toggleVisibility(!!visible)
                            "
                            @select.prevent
                        >
                            {{ columnLabel(column.columnDef) }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <Table>
                <TableHeader class="bg-muted">
                    <TableRow
                        v-for="headerGroup in table.getHeaderGroups()"
                        :key="headerGroup.id"
                    >
                        <TableHead
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            :aria-sort="
                                isSortable(header.column.columnDef)
                                    ? ariaSort(header.column.id)
                                    : undefined
                            "
                            class="h-11 px-4 font-medium text-muted-foreground"
                        >
                            <template v-if="!header.isPlaceholder">
                                <button
                                    v-if="isSortable(header.column.columnDef)"
                                    type="button"
                                    class="-mx-1 inline-flex items-center gap-1 rounded px-1 hover:text-foreground"
                                    :data-test="`sort-${header.column.id}`"
                                    @click="toggleSort(header.column.id)"
                                >
                                    <FlexRender
                                        :render="header.column.columnDef.header"
                                        :props="header.getContext()"
                                    />
                                    <ArrowUp
                                        v-if="
                                            sortDirection(header.column.id) ===
                                            'asc'
                                        "
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            sortDirection(header.column.id) ===
                                            'desc'
                                        "
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3.5 opacity-40"
                                        aria-hidden="true"
                                    />
                                </button>
                                <FlexRender
                                    v-else
                                    :render="header.column.columnDef.header"
                                    :props="header.getContext()"
                                />
                            </template>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="row in table.getRowModel().rows"
                        :key="row.id"
                    >
                        <TableCell
                            v-for="cell in row.getVisibleCells()"
                            :key="cell.id"
                            class="px-4 py-3"
                        >
                            <FlexRender
                                :render="cell.column.columnDef.cell"
                                :props="cell.getContext()"
                            />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="rows.data.length === 0">
                        <TableCell
                            :colspan="table.getVisibleLeafColumns().length"
                            class="p-0"
                        >
                            <EmptyState
                                :title="emptyTitle"
                                :description="emptyDescription"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <nav
            v-if="meta.total > 0"
            aria-label="Pagination"
            class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <p data-test="data-table-summary">
                Showing {{ firstShown }}–{{ lastShown }} of {{ meta.total }}
            </p>
            <div v-if="meta.last_page > 1" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.current_page <= 1"
                    data-test="data-table-previous"
                    @click="visit({ page: meta.current_page - 1 })"
                >
                    Previous
                </Button>
                <span
                    >Page {{ meta.current_page }} of {{ meta.last_page }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.current_page >= meta.last_page"
                    data-test="data-table-next"
                    @click="visit({ page: meta.current_page + 1 })"
                >
                    Next
                </Button>
            </div>
        </nav>
    </div>
</template>
