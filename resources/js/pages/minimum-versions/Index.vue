<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MinimumVersionController from '@/actions/App/Http/Controllers/MinimumVersionController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/datetime';

/*
 * Minimum versions (SPEC §13.6, §11.6): the lowest safe upstream version per
 * software (per branch for PHP). Below it, a server's versions check warns.
 */
type Row = App.Data.MinimumVersionRowData;

const props = defineProps<{
    minimums: Row[];
    software: { value: string; label: string }[];
    php_branches: string[];
}>();

const page = usePage();

const editing = ref<Row | null>(null);
const open = ref(false);
const deleting = ref<Row | null>(null);

const form = useForm({
    software: 'nginx',
    branch: '',
    min_version: '',
    cve_url: '',
    note: '',
});

function add(): void {
    editing.value = null;
    form.defaults({
        software: 'nginx',
        branch: '',
        min_version: '',
        cve_url: '',
        note: '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function edit(row: Row): void {
    editing.value = row;
    form.defaults({
        software: row.software,
        branch: row.branch,
        min_version: row.min_version,
        cve_url: row.cve_url ?? '',
        note: row.note ?? '',
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (editing.value) {
        form.put(
            MinimumVersionController.update(editing.value.id).url,
            options,
        );
    } else {
        form.post(MinimumVersionController.store().url, options);
    }
}

function destroy(): void {
    if (deleting.value) {
        router.delete(MinimumVersionController.destroy(deleting.value.id).url, {
            preserveScroll: true,
            onFinish: () => (deleting.value = null),
        });
    }
}

const isPhp = computed(() => form.software === 'php');

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="Minimum versions" />

    <div
        class="flex w-full max-w-[1100px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Minimum versions"
            description="The lowest safe version of each piece of software. Servers below it get a warning; package versions are shown beside so you can spot a backported fix."
        >
            <template v-if="page.props.can.manage" #actions>
                <Button @click="add">+ Set a minimum</Button>
            </template>
        </PageHeader>

        <section class="overflow-x-auto rounded-xl border bg-card">
            <EmptyState
                v-if="minimums.length === 0"
                title="No minimums set"
                description="Set one after a security advisory, e.g. nginx 1.26.2."
            />
            <table v-else class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-5 py-3 font-medium">Software</th>
                        <th class="px-5 py-3 font-medium">Minimum</th>
                        <th class="px-5 py-3 font-medium">Servers below</th>
                        <th class="px-5 py-3 font-medium">Set by</th>
                        <th v-if="page.props.can.manage" class="px-5 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in minimums" :key="row.id" class="align-top">
                        <td class="px-5 py-3">
                            <span class="font-medium">{{ row.label }}</span>
                            <a
                                v-if="row.cve_url"
                                :href="row.cve_url"
                                target="_blank"
                                rel="noopener"
                                class="ml-2 text-primary underline"
                                >CVE</a
                            >
                            <p v-if="row.note" class="text-muted-foreground">
                                {{ row.note }}
                            </p>
                        </td>
                        <td class="px-5 py-3 font-mono">
                            {{ row.min_version }}
                        </td>
                        <td class="px-5 py-3">
                            <span
                                v-if="row.servers_below.length === 0"
                                class="text-status-ok-fg"
                                >None</span
                            >
                            <span
                                v-else
                                class="flex flex-col gap-0.5 text-status-warning-fg"
                            >
                                <span class="font-semibold">{{
                                    row.servers_below.length
                                }}</span>
                                <Link
                                    v-for="server in row.servers_below"
                                    :key="server.id"
                                    :href="ServerController.show(server.id)"
                                    class="hover:underline"
                                >
                                    {{ server.name }}
                                    <span class="font-mono">{{
                                        server.version
                                    }}</span>
                                </Link>
                            </span>
                        </td>
                        <td class="px-5 py-3 text-muted-foreground">
                            {{ row.set_by }} ·
                            {{ formatDate(row.updated_at.slice(0, 10)) }}
                        </td>
                        <td
                            v-if="page.props.can.manage"
                            class="px-5 py-3 text-right whitespace-nowrap"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="edit(row)"
                                >Edit</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                @click="deleting = row"
                                >Remove</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? `Edit minimum ${editing.label}`
                            : 'Set a minimum version'
                    }}</DialogTitle>
                    <DialogDescription
                        >Saving re-checks every server's versions right
                        away.</DialogDescription
                    >
                </DialogHeader>
                <form
                    id="minimum-form"
                    class="flex flex-col gap-4"
                    novalidate
                    @submit.prevent="submit"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Software"
                            for="software"
                            :error="form.errors.software"
                        >
                            <select
                                id="software"
                                v-model="form.software"
                                :class="fieldClasses"
                                :disabled="editing !== null"
                            >
                                <option
                                    v-for="item in software"
                                    :key="item.value"
                                    :value="item.value"
                                >
                                    {{ item.label }}
                                </option>
                            </select>
                        </FormField>
                        <FormField
                            v-if="isPhp"
                            label="PHP branch"
                            for="branch"
                            :error="form.errors.branch"
                            required
                        >
                            <Input
                                id="branch"
                                v-model="form.branch"
                                class="font-mono"
                                placeholder="8.2"
                                list="php-branches"
                                :disabled="editing !== null"
                            />
                            <datalist id="php-branches">
                                <option
                                    v-for="branch in php_branches"
                                    :key="branch"
                                    :value="branch"
                                />
                            </datalist>
                        </FormField>
                    </div>
                    <FormField
                        label="Minimum version"
                        for="min_version"
                        :error="form.errors.min_version"
                        required
                    >
                        <Input
                            id="min_version"
                            v-model="form.min_version"
                            class="font-mono"
                            placeholder="1.26.2"
                        />
                    </FormField>
                    <FormField
                        label="CVE or advisory link"
                        for="cve_url"
                        :error="form.errors.cve_url"
                    >
                        <Input id="cve_url" v-model="form.cve_url" type="url" />
                    </FormField>
                    <FormField
                        label="Note"
                        for="note"
                        :error="form.errors.note"
                    >
                        <Input id="note" v-model="form.note" maxlength="255" />
                    </FormField>
                </form>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="minimum-form"
                        :disabled="form.processing"
                        >Save minimum</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Remove the minimum for ${deleting?.label}?`"
            confirm-label="Remove"
            @update:open="(value) => !value && (deleting = null)"
            @confirm="destroy"
        >
            Servers below it stop warning about it.
        </ConfirmDialog>
    </div>
</template>
