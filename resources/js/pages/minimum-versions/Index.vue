<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MinimumVersionController from '@/actions/App/Http/Controllers/MinimumVersionController';
import PackageMinimumController from '@/actions/App/Http/Controllers/PackageMinimumController';
import RepositoryController from '@/actions/App/Http/Controllers/RepositoryController';
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
 * Minimum versions (SPEC §13.6, §11.6): the lowest safe upstream versions of
 * each software, one per branch. A reading is compared with the version of
 * the nearest listed branch at or below its own; a branch older than every
 * listed one is below. Below it, a server's versions check warns.
 */
type Row = App.Data.MinimumVersionRowData;

type PackageRow = App.Data.PackageMinimumRowData;

const props = defineProps<{
    minimums: Row[];
    software: { value: string; label: string }[];
    package_minimums: PackageRow[];
    ecosystems: { value: App.Enums.Ecosystem; label: string }[];
    package_names: string[];
}>();

const page = usePage();

const editing = ref<Row | null>(null);
const open = ref(false);
const deleting = ref<Row | null>(null);

const form = useForm({
    software: 'nginx',
    min_versions: '',
    cve_url: '',
    note: '',
});

function add(): void {
    editing.value = null;
    form.defaults({
        software: 'nginx',
        min_versions: '',
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
        min_versions: row.min_versions.join(', '),
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

// Errors for one listed version come back as `min_versions.0`, `min_versions.1`, ….
const versionsError = computed(
    () =>
        form.errors.min_versions ??
        Object.entries(form.errors as Record<string, string>).find(([key]) =>
            key.startsWith('min_versions.'),
        )?.[1],
);

/*
 * Package minimums (SPEC §11.9): one version per major, e.g.
 * laravel/framework 12.13, 13.39. Repositories whose lock file has the
 * package below it get a warning.
 */
const editingPackage = ref<PackageRow | null>(null);
const packageOpen = ref(false);
const deletingPackage = ref<PackageRow | null>(null);

const packageForm = useForm({
    ecosystem: 'composer' as App.Enums.Ecosystem,
    name: '',
    min_versions: '',
    cve_url: '',
    note: '',
});

function addPackage(): void {
    editingPackage.value = null;
    packageForm.defaults({
        ecosystem: 'composer',
        name: '',
        min_versions: '',
        cve_url: '',
        note: '',
    });
    packageForm.reset();
    packageForm.clearErrors();
    packageOpen.value = true;
}

function editPackage(row: PackageRow): void {
    editingPackage.value = row;
    packageForm.defaults({
        ecosystem: row.ecosystem,
        name: row.name,
        min_versions: row.min_versions.join(', '),
        cve_url: row.cve_url ?? '',
        note: row.note ?? '',
    });
    packageForm.reset();
    packageForm.clearErrors();
    packageOpen.value = true;
}

function submitPackage(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (packageOpen.value = false),
    };

    if (editingPackage.value) {
        packageForm.put(
            PackageMinimumController.update(editingPackage.value.id).url,
            options,
        );
    } else {
        packageForm.post(PackageMinimumController.store().url, options);
    }
}

function destroyPackage(): void {
    if (deletingPackage.value) {
        router.delete(
            PackageMinimumController.destroy(deletingPackage.value.id).url,
            {
                preserveScroll: true,
                onFinish: () => (deletingPackage.value = null),
            },
        );
    }
}

const packageVersionsError = computed(
    () =>
        packageForm.errors.min_versions ??
        Object.entries(packageForm.errors as Record<string, string>).find(
            ([key]) => key.startsWith('min_versions.'),
        )?.[1],
);

const ecosystemLabels: Record<App.Enums.Ecosystem, string> = {
    composer: 'Composer',
    npm: 'npm',
};

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
                description="Set one after a security advisory, e.g. nginx 1.30.7, 1.31.9."
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
                            {{ row.min_versions.join(', ') }}
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

        <div class="flex flex-wrap items-end justify-between gap-4 pt-2">
            <div>
                <h2 class="text-xl font-bold tracking-tight">Packages</h2>
                <p class="mt-1 text-muted-foreground">
                    Composer and npm packages locked in the repositories that
                    sites and applications link to, one version per major. Every
                    package in the lock files is checked, including ones pulled
                    in by other packages (e.g. livewire/livewire through
                    filament/filament). Repositories below it get a warning.
                </p>
            </div>
            <Button v-if="page.props.can.manage" @click="addPackage"
                >+ Set a package minimum</Button
            >
        </div>

        <section class="overflow-x-auto rounded-xl border bg-card">
            <EmptyState
                v-if="package_minimums.length === 0"
                title="No package minimums set"
                description="Set one after a security advisory, e.g. laravel/framework 12.13, 13.39."
            />
            <table v-else class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-5 py-3 font-medium">Package</th>
                        <th class="px-5 py-3 font-medium">Minimum</th>
                        <th class="px-5 py-3 font-medium">
                            Repositories below
                        </th>
                        <th class="px-5 py-3 font-medium">Set by</th>
                        <th v-if="page.props.can.manage" class="px-5 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="row in package_minimums"
                        :key="row.id"
                        class="align-top"
                    >
                        <td class="px-5 py-3">
                            <span class="font-mono font-medium">{{
                                row.name
                            }}</span>
                            <span class="ml-2 text-muted-foreground">{{
                                ecosystemLabels[row.ecosystem]
                            }}</span>
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
                            {{ row.min_versions.join(', ') }}
                        </td>
                        <td class="px-5 py-3">
                            <span
                                v-if="row.repositories_below.length === 0"
                                class="text-status-ok-fg"
                                >None</span
                            >
                            <span
                                v-else
                                class="flex flex-col gap-0.5 text-status-warning-fg"
                            >
                                <span class="font-semibold">{{
                                    row.repositories_below.length
                                }}</span>
                                <Link
                                    v-for="repository in row.repositories_below"
                                    :key="repository.id"
                                    :href="
                                        RepositoryController.show(repository.id)
                                    "
                                    class="hover:underline"
                                >
                                    {{ repository.name }}
                                    <span class="font-mono">{{
                                        repository.version
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
                                @click="editPackage(row)"
                                >Edit</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                @click="deletingPackage = row"
                                >Remove</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <Dialog v-model:open="packageOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editingPackage
                            ? `Edit minimum ${editingPackage.name}`
                            : 'Set a package minimum'
                    }}</DialogTitle>
                    <DialogDescription
                        >Saving re-reads every repository right
                        away.</DialogDescription
                    >
                </DialogHeader>
                <form
                    id="package-minimum-form"
                    class="flex flex-col gap-4"
                    novalidate
                    @submit.prevent="submitPackage"
                >
                    <FormField
                        label="Ecosystem"
                        for="ecosystem"
                        :error="packageForm.errors.ecosystem"
                    >
                        <select
                            id="ecosystem"
                            v-model="packageForm.ecosystem"
                            :class="fieldClasses"
                            :disabled="editingPackage !== null"
                        >
                            <option
                                v-for="item in ecosystems"
                                :key="item.value"
                                :value="item.value"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        label="Package"
                        for="package-name"
                        :error="packageForm.errors.name"
                        required
                    >
                        <Input
                            id="package-name"
                            v-model="packageForm.name"
                            class="font-mono"
                            list="package-names"
                            :placeholder="
                                packageForm.ecosystem === 'composer'
                                    ? 'laravel/framework'
                                    : 'axios'
                            "
                            :readonly="editingPackage !== null"
                        />
                        <datalist id="package-names">
                            <option
                                v-for="name in package_names"
                                :key="name"
                                :value="name"
                            />
                        </datalist>
                    </FormField>
                    <FormField
                        label="Minimum versions"
                        for="package-min-versions"
                        :error="packageVersionsError"
                        description="One per major version, separated by commas, e.g. 12.13, 13.39. A major older than the oldest listed is always below; a newer one never is."
                        required
                    >
                        <Input
                            id="package-min-versions"
                            v-model="packageForm.min_versions"
                            class="font-mono"
                            placeholder="12.13, 13.39"
                        />
                    </FormField>
                    <FormField
                        label="CVE or advisory link"
                        for="package-cve-url"
                        :error="packageForm.errors.cve_url"
                    >
                        <Input
                            id="package-cve-url"
                            v-model="packageForm.cve_url"
                            type="url"
                        />
                    </FormField>
                    <FormField
                        label="Note"
                        for="package-note"
                        :error="packageForm.errors.note"
                    >
                        <Input
                            id="package-note"
                            v-model="packageForm.note"
                            maxlength="255"
                        />
                    </FormField>
                </form>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="packageOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="package-minimum-form"
                        :disabled="packageForm.processing"
                        >Save minimum</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="deletingPackage !== null"
            :title="`Remove the minimum for ${deletingPackage?.name}?`"
            confirm-label="Remove"
            @update:open="(value) => !value && (deletingPackage = null)"
            @confirm="destroyPackage"
        >
            Repositories below it stop warning about it.
        </ConfirmDialog>

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
                        label="Minimum versions"
                        for="min_versions"
                        :error="versionsError"
                        description="One per branch, separated by commas, e.g. 8.2.36, 8.3.37. A branch older than the oldest listed is always below."
                        required
                    >
                        <Input
                            id="min_versions"
                            v-model="form.min_versions"
                            class="font-mono"
                            placeholder="8.2.36, 8.3.37, 8.4.28"
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
