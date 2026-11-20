<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { onKeyStroke, useDebounceFn } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import StatusDot from '@/components/app/StatusDot.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { search as searchRoute } from '@/routes';

/*
 * Global search (SPEC §13.8): the header field opens a dialog (also
 * Ctrl/⌘ + K anywhere). Results come from GET /search, grouped by type, with
 * a status dot each. Arrow keys move, Enter opens the highlighted result
 * (the first one by default).
 */
type Result = App.Data.SearchResultData;

const open = ref(false);
const query = ref('');
const results = ref<Result[]>([]);
const loading = ref(false);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const shortcut = isMac ? '⌘ K' : 'Ctrl K';

const typeLabels: Record<App.Enums.SearchResultType, string> = {
    server: 'Servers',
    external_service: 'External services',
    domain: 'Domains',
    site: 'Sites',
};

const groups = computed(() => {
    const byType = new Map<
        App.Enums.SearchResultType,
        { result: Result; index: number }[]
    >();

    results.value.forEach((result, index) => {
        byType.set(result.type, [
            ...(byType.get(result.type) ?? []),
            { result, index },
        ]);
    });

    return [...byType.entries()].map(([type, items]) => ({ type, items }));
});

let controller: AbortController | null = null;

async function fetchResults(term: string): Promise<void> {
    controller?.abort();

    if (term.length < 2) {
        results.value = [];
        loading.value = false;

        return;
    }

    controller = new AbortController();
    loading.value = true;

    try {
        const response = await fetch(searchRoute.url({ query: { q: term } }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        const body = (await response.json()) as { results: Result[] };

        results.value = body.results;
        active.value = 0;
        loading.value = false;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            results.value = [];
            loading.value = false;
        }
    }
}

const searchSoon = useDebounceFn(fetchResults, 250);

watch(query, (term) => {
    if (term.trim().length < 2) {
        void fetchResults('');
    } else {
        loading.value = true;
        void searchSoon(term.trim());
    }
});

watch(open, async (isOpen) => {
    if (isOpen) {
        await nextTick();
        input.value?.focus();
    } else {
        query.value = '';
        results.value = [];
    }
});

onKeyStroke(['k', 'K'], (event) => {
    if (event.metaKey || event.ctrlKey) {
        event.preventDefault();
        open.value = !open.value;
    }
});

function go(result: Result | undefined): void {
    if (result) {
        open.value = false;
        router.visit(result.url);
    }
}

function onKeydown(event: KeyboardEvent): void {
    const count = results.value.length;

    if (event.key === 'ArrowDown' && count > 0) {
        event.preventDefault();
        active.value = (active.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count > 0) {
        event.preventDefault();
        active.value = (active.value - 1 + count) % count;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(results.value[active.value]);
    }
}

const optionId = (index: number) => `global-search-option-${index}`;
</script>

<template>
    <div class="min-w-0">
        <button
            type="button"
            class="flex h-11 w-full max-w-[580px] min-w-0 items-center gap-2.5 rounded-[10px] border border-input bg-muted pr-3 pl-3.5 text-left text-sm text-muted-foreground hover:bg-accent"
            data-test="global-search-open"
            @click="open = true"
        >
            <Search class="size-[18px] shrink-0" aria-hidden="true" />
            <span class="truncate"
                >Search servers, domains, IPs, versions…</span
            >
            <kbd
                class="ml-auto hidden rounded-md border border-input bg-card px-1.5 py-0.5 font-sans text-xs text-muted-foreground sm:inline"
                >{{ shortcut }}</kbd
            >
        </button>

        <Dialog v-model:open="open">
            <DialogContent
                class="top-[12vh] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-[640px]"
                :show-close-button="false"
            >
                <DialogTitle class="sr-only">Search</DialogTitle>
                <DialogDescription class="sr-only">
                    Servers by name, hostname, IP or OS; domains; sites by
                    hostname or description.
                </DialogDescription>
                <div class="flex items-center gap-3 border-b px-4">
                    <Search
                        class="size-[18px] shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        ref="input"
                        v-model="query"
                        type="text"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="global-search-results"
                        :aria-activedescendant="
                            results.length > 0 ? optionId(active) : undefined
                        "
                        aria-autocomplete="list"
                        aria-label="Search"
                        placeholder="Search servers, domains, IPs, versions…"
                        class="h-14 w-full bg-transparent text-base outline-none placeholder:text-muted-foreground"
                        data-test="global-search-input"
                        @keydown="onKeydown"
                    />
                </div>

                <div
                    id="global-search-results"
                    role="listbox"
                    aria-label="Search results"
                    class="max-h-[60vh] overflow-y-auto p-2"
                >
                    <p
                        v-if="query.trim().length < 2"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        Type at least 2 characters.
                    </p>
                    <p
                        v-else-if="loading && results.length === 0"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        Searching…
                    </p>
                    <p
                        v-else-if="results.length === 0"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                        data-test="global-search-empty"
                    >
                        Nothing matches “{{ query.trim() }}”.
                    </p>
                    <div
                        v-for="group in groups"
                        :key="group.type"
                        role="group"
                        :aria-labelledby="`global-search-group-${group.type}`"
                        class="py-1"
                    >
                        <p
                            :id="`global-search-group-${group.type}`"
                            class="px-3 py-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ typeLabels[group.type] }}
                        </p>
                        <div
                            v-for="{ result, index } in group.items"
                            :id="optionId(index)"
                            :key="`${result.type}-${result.id}`"
                            role="option"
                            :aria-selected="index === active"
                            class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2"
                            :class="index === active ? 'bg-accent' : ''"
                            :data-test="`global-search-option-${index}`"
                            @mousemove="active = index"
                            @click="go(result)"
                        >
                            <StatusDot
                                v-if="result.status"
                                :status="result.status"
                                label
                            />
                            <span
                                v-else
                                class="size-2 shrink-0 rounded-full border border-dashed border-status-unknown"
                                aria-hidden="true"
                            />
                            <span
                                class="shrink-0 font-medium"
                                :class="
                                    result.type === 'external_service'
                                        ? ''
                                        : 'font-mono'
                                "
                                >{{ result.label }}</span
                            >
                            <span
                                class="truncate text-sm text-muted-foreground"
                                >{{ result.context }}</span
                            >
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
