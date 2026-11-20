<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import ChoiceController from '@/actions/App/Http/Controllers/ChoiceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/* One admin-editable list (SPEC §3.4): rename, reorder, delete unused, add. */
const props = defineProps<{
    list: App.Enums.ChoiceList;
    title: string;
    description: string;
    items: App.Data.ChoiceRowData[];
}>();

const page = usePage();

const labels = reactive<Record<number, string>>({});

watch(
    () => props.items,
    (items) => items.forEach((item) => (labels[item.id] = item.label)),
    { immediate: true },
);

const adding = useForm({ list: props.list, label: '' });

function add(): void {
    adding.post(ChoiceController.store().url, {
        preserveScroll: true,
        onSuccess: () => adding.reset('label'),
    });
}

// The row whose rename failed, and why.
const renameFailure = ref<{ id: number; message: string } | null>(null);

function rename(item: App.Data.ChoiceRowData): void {
    renameFailure.value = null;

    router.put(
        ChoiceController.update(item.id),
        { label: labels[item.id] },
        {
            preserveScroll: true,
            onError: (errors) =>
                (renameFailure.value = {
                    id: item.id,
                    message: errors.label ?? 'Could not rename.',
                }),
        },
    );
}

function move(item: App.Data.ChoiceRowData, direction: 'up' | 'down'): void {
    router.post(
        ChoiceController.move(item.id),
        { direction },
        { preserveScroll: true },
    );
}

function destroy(item: App.Data.ChoiceRowData): void {
    router.delete(ChoiceController.destroy(item.id), { preserveScroll: true });
}

const renameError = (id: number) =>
    renameFailure.value?.id === id ? renameFailure.value.message : undefined;
</script>

<template>
    <section
        class="rounded-xl border bg-card"
        :aria-labelledby="`list-${list}`"
    >
        <div class="px-6 pt-5 pb-4">
            <h2 :id="`list-${list}`" class="text-lg font-semibold">
                {{ title }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <ol class="divide-y border-t">
            <li
                v-for="(item, index) in items"
                :key="item.id"
                class="flex flex-wrap items-center gap-3 px-6 py-3"
            >
                <template v-if="page.props.can.manage">
                    <form
                        class="flex min-w-0 flex-1 flex-col gap-1"
                        @submit.prevent="rename(item)"
                    >
                        <div class="flex gap-2">
                            <Input
                                v-model="labels[item.id]"
                                class="min-w-40 flex-1"
                                :aria-label="`Name of ${item.label}`"
                            />
                            <Button
                                v-if="labels[item.id] !== item.label"
                                type="submit"
                                size="sm"
                                variant="outline"
                                class="h-9"
                            >
                                Rename
                            </Button>
                        </div>
                        <InputError :message="renameError(item.id)" />
                    </form>
                    <span class="w-28 text-right text-xs text-muted-foreground">
                        {{
                            item.servers_count === 0
                                ? 'Not used'
                                : `${item.servers_count} ${item.servers_count === 1 ? 'server' : 'servers'}`
                        }}
                    </span>
                    <div class="flex gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :disabled="index === 0"
                            :aria-label="`Move ${item.label} up`"
                            @click="move(item, 'up')"
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :disabled="index === items.length - 1"
                            :aria-label="`Move ${item.label} down`"
                            @click="move(item, 'down')"
                        >
                            <ArrowDown />
                        </Button>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        :disabled="item.servers_count > 0"
                        :title="
                            item.servers_count > 0
                                ? 'In use, so it can only be renamed'
                                : undefined
                        "
                        @click="destroy(item)"
                    >
                        Delete
                    </Button>
                </template>
                <template v-else>
                    <span class="flex-1">{{ item.label }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{
                            item.servers_count === 0
                                ? 'Not used'
                                : `${item.servers_count} ${item.servers_count === 1 ? 'server' : 'servers'}`
                        }}
                    </span>
                </template>
            </li>
        </ol>
        <form
            v-if="page.props.can.manage"
            class="flex flex-col gap-1 border-t px-6 py-4"
            @submit.prevent="add"
        >
            <div class="flex gap-2">
                <Input
                    v-model="adding.label"
                    class="flex-1"
                    :placeholder="`Add to ${title.toLowerCase()}`"
                    :aria-label="`New entry for ${title.toLowerCase()}`"
                />
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="adding.processing || adding.label.trim() === ''"
                >
                    Add
                </Button>
            </div>
            <InputError :message="adding.errors.label" />
        </form>
    </section>
</template>
