<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import EmptyState from '@/components/app/EmptyState.vue';
import ExpiryDate from '@/components/app/ExpiryDate.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RepoLink from '@/components/app/RepoLink.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { statusLabels } from '@/lib/status';
import type { TableProps } from '@/types';

/*
 * Domains with expandable subdomains (SPEC §13.6). Filtering and paging are
 * server-side like every list (§13.5); the rows expand in place.
 */
type Row = App.Data.DomainRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: { all: number; sites: number; deleted: number };
    }
>();

const page = usePage();

/* Filter */

const search = ref(props.filters.search ?? '');

function visit(query: Record<string, string | number | null>): void {
    const filter: Record<string, string> = {};
    const search = query.search ?? props.filters.search ?? '';
    const show =
        query.show !== undefined ? query.show : (props.filters.show ?? null);

    if (search !== '') {
        filter.search = String(search);
    }

    if (show) {
        filter.show = String(show);
    }

    router.get(
        DomainController.index().url,
        {
            ...(Object.keys(filter).length > 0 ? { filter } : {}),
            ...(props.sort && props.sort !== 'name'
                ? { sort: props.sort }
                : {}),
            ...(query.page && Number(query.page) > 1
                ? { page: query.page }
                : {}),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const searchSoon = useDebounceFn((value: string) => {
    if (value.trim() !== (props.filters.search ?? '')) {
        visit({ search: value.trim() });
    }
}, 300);

watch(search, (value) => searchSoon(value));

/* Expanding */

const expanded = ref(new Set<number>());

// While filtering, show the subdomains that matched.
watch(
    () => [props.filters.search, props.rows.data] as const,
    ([term, rows]) => {
        expanded.value = term ? new Set(rows.map((row) => row.id)) : new Set();
    },
    { immediate: true },
);

function toggle(id: number): void {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

const expandAll = () =>
    (expanded.value = new Set(props.rows.data.map((row) => row.id)));
const collapseAll = () => (expanded.value = new Set());

/* Paging */

const meta = computed(() => props.rows.meta);

function problems(row: Row): string {
    const parts = [];

    if (row.failing_count > 0) {
        parts.push(`${row.failing_count} failing`);
    }

    if (row.warning_count > 0) {
        parts.push(`${row.warning_count} warning`);
    }

    return parts.join(', ');
}
</script>

<template>
    <Head title="Domains" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Domains"
            :description="`${counts.all} ${counts.all === 1 ? 'domain' : 'domains'} · ${counts.sites} ${counts.sites === 1 ? 'subdomain' : 'subdomains'} · open a domain to see how each subdomain is served`"
        >
            <template #actions>
                <template v-if="page.props.can.manage">
                    <Button variant="outline" as-child>
                        <Link :href="DomainController.create()"
                            >+ Add domain</Link
                        >
                    </Button>
                    <Button as-child>
                        <Link :href="SiteController.create()">+ Add site</Link>
                    </Button>
                </template>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-80">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Filter, e.g. web03 or cloudflare"
                        aria-label="Filter domains, sites, servers and services"
                        class="bg-card pl-9"
                        data-test="domains-search"
                    />
                </div>
                <Button variant="outline" @click="expandAll">Expand all</Button>
                <Button variant="outline" @click="collapseAll"
                    >Collapse all</Button
                >
                <Button
                    v-if="counts.deleted > 0"
                    variant="outline"
                    :aria-pressed="filters.show === 'deleted'"
                    @click="
                        visit({
                            show: filters.show === 'deleted' ? null : 'deleted',
                        })
                    "
                >
                    {{
                        filters.show === 'deleted'
                            ? 'Show live domains'
                            : `Deleted ${counts.deleted}`
                    }}
                </Button>
            </div>
            <div
                class="flex items-center gap-4 text-sm text-muted-foreground"
                aria-hidden="true"
            >
                <span class="flex items-center gap-1.5"
                    ><span
                        class="h-3.5 w-5 rounded border-[1.5px] border-dashed border-status-unknown"
                    />External service</span
                >
                <span class="flex items-center gap-1.5"
                    ><span
                        class="h-3.5 w-5 rounded border border-blue-300 bg-blue-50"
                    />Our server</span
                >
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-muted text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="w-14 px-4 py-3">
                            <span class="sr-only">Expand</span>
                        </th>
                        <th scope="col" class="px-2 py-3 font-medium">
                            Domain
                        </th>
                        <th scope="col" class="px-2 py-3 font-medium">
                            Subdomains
                        </th>
                        <th scope="col" class="px-2 py-3 font-medium">
                            Expires
                        </th>
                        <th scope="col" class="px-2 py-3 font-medium">
                            Registrar
                        </th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody v-for="row in rows.data" :key="row.id" class="border-t">
                    <tr>
                        <td class="px-4 py-3">
                            <Button
                                variant="outline"
                                size="icon"
                                class="size-8"
                                :aria-expanded="expanded.has(row.id)"
                                :aria-controls="`domain-${row.id}-sites`"
                                :aria-label="`${expanded.has(row.id) ? 'Collapse' : 'Expand'} ${row.name}`"
                                @click="toggle(row.id)"
                            >
                                <ChevronDown v-if="expanded.has(row.id)" />
                                <ChevronRight v-else />
                            </Button>
                        </td>
                        <td class="px-2 py-3">
                            <Link
                                :href="DomainController.show(row.id)"
                                class="font-mono text-[15px] font-semibold hover:underline"
                                >{{ row.name }}</Link
                            >
                            <span
                                v-if="row.deleted"
                                class="ml-2 text-xs text-muted-foreground"
                                >Deleted</span
                            >
                        </td>
                        <td class="px-2 py-3">
                            {{ row.sites.length }}
                            {{
                                row.sites.length === 1
                                    ? 'subdomain'
                                    : 'subdomains'
                            }}
                            <span
                                v-if="problems(row)"
                                class="font-medium text-status-error-fg"
                            >
                                · {{ problems(row) }}</span
                            >
                        </td>
                        <td class="px-2 py-3">
                            <ExpiryDate
                                :date="row.expires_at"
                                :days="row.expires_in_days"
                            />
                        </td>
                        <td class="px-2 py-3">{{ row.registrar ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="row.status" />
                        </td>
                    </tr>
                    <tr
                        v-show="expanded.has(row.id)"
                        :id="`domain-${row.id}-sites`"
                        class="bg-muted/40"
                    >
                        <td />
                        <td colspan="5" class="px-2 pt-1 pb-4">
                            <ul v-if="row.sites.length > 0" class="divide-y">
                                <li
                                    v-for="site in row.sites"
                                    :key="site.id"
                                    class="grid items-center gap-3 py-3 md:grid-cols-[2fr_2fr_1fr]"
                                >
                                    <div>
                                        <span
                                            class="inline-flex items-center gap-2"
                                        >
                                            <Link
                                                :href="
                                                    SiteController.show(site.id)
                                                "
                                                class="font-mono hover:underline"
                                                >{{ site.hostname }}</Link
                                            >
                                            <RepoLink
                                                v-if="site.github_url"
                                                :url="site.github_url"
                                            />
                                        </span>
                                        <p
                                            v-if="site.description"
                                            class="text-muted-foreground"
                                        >
                                            {{ site.description }}
                                        </p>
                                    </div>
                                    <ServingPath
                                        :path="site.serving_path"
                                        :backend-href="
                                            site.server_id
                                                ? ServerController.show(
                                                      site.server_id,
                                                  )
                                                : undefined
                                        "
                                    />
                                    <span
                                        class="inline-flex items-center gap-2"
                                    >
                                        <StatusDot :status="site.status" />
                                        {{ statusLabels[site.status] }}
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="py-3 text-muted-foreground">
                                No subdomains yet.
                            </p>
                            <Link
                                v-if="page.props.can.manage && !row.deleted"
                                :href="
                                    SiteController.create({
                                        query: { domain: row.id },
                                    })
                                "
                                class="mt-2 inline-flex h-9 items-center rounded-lg border border-dashed border-status-unknown px-3 text-sm text-primary hover:bg-card"
                                >+ Add site on {{ row.name }}</Link
                            >
                        </td>
                    </tr>
                </tbody>
                <tbody v-if="rows.data.length === 0">
                    <tr>
                        <td colspan="6">
                            <EmptyState
                                :title="
                                    filters.search
                                        ? 'Nothing matches the filter'
                                        : 'No domains yet'
                                "
                                :description="
                                    filters.search
                                        ? undefined
                                        : 'Add the domains we renew to start the map.'
                                "
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="meta.total > 0"
            aria-label="Pagination"
            class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <p>Showing {{ rows.data.length }} of {{ meta.total }} domains</p>
            <div v-if="meta.last_page > 1" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.current_page <= 1"
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
                    @click="visit({ page: meta.current_page + 1 })"
                >
                    Next
                </Button>
            </div>
        </nav>
    </div>
</template>
