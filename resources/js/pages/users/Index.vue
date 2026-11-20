<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h, ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/UserController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import DataTable from '@/components/app/DataTable.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { TableFilterChip, TableProps } from '@/types';

/* Users and roles (SPEC §13.6). Admins only. */
type Row = App.Data.UserRowData;

const props = defineProps<
    TableProps<Row> & {
        counts: { all: number; admin: number; reader: number };
        password_rules: string;
    }
>();

const roleLabels: Record<App.Enums.UserRole, string> = {
    admin: 'Admin',
    reader: 'Reader',
};

const selectClasses =
    'h-9 rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

function changeRole(user: Row, role: App.Enums.UserRole): void {
    if (role !== user.role) {
        router.put(
            UserController.updateRole(user.id),
            { role },
            { preserveScroll: true },
        );
    }
}

const deleting = ref<Row | null>(null);
const confirmingDelete = computed({
    get: () => deleting.value !== null,
    set: (open: boolean) => {
        if (!open) {
            deleting.value = null;
        }
    },
});
const processing = ref(false);

function destroy(): void {
    if (deleting.value === null) {
        return;
    }

    router.delete(UserController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleting.value = null;
        },
    });
}

const columns: ColumnDef<Row, unknown>[] = [
    {
        id: 'name',
        header: 'Name',
        enableSorting: true,
        cell: ({ row }) =>
            h('span', { class: 'font-medium' }, [
                row.original.name,
                row.original.is_you
                    ? h(
                          'span',
                          {
                              class: 'ml-2 text-xs font-normal text-muted-foreground',
                          },
                          '(you)',
                      )
                    : null,
            ]),
    },
    {
        id: 'email',
        header: 'Email',
        enableSorting: true,
        cell: ({ row }) => row.original.email,
    },
    {
        id: 'role',
        header: 'Role',
        cell: ({ row }) =>
            h(
                'select',
                {
                    class: selectClasses,
                    value: row.original.role,
                    'aria-label': `Role of ${row.original.name}`,
                    onChange: (event: Event) =>
                        changeRole(
                            row.original,
                            (event.target as HTMLSelectElement)
                                .value as App.Enums.UserRole,
                        ),
                },
                Object.entries(roleLabels).map(([value, label]) =>
                    h('option', { value }, label),
                ),
            ),
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
    {
        id: 'actions',
        header: '',
        cell: ({ row }) =>
            h(
                Button,
                {
                    variant: 'ghost',
                    size: 'sm',
                    class: 'text-destructive',
                    onClick: () => (deleting.value = row.original),
                },
                () => 'Delete',
            ),
    },
];

const chips = computed<TableFilterChip[]>(() => [
    { label: 'All', value: null, count: props.counts.all },
    { label: 'Admins', value: 'admin', count: props.counts.admin },
    { label: 'Readers', value: 'reader', count: props.counts.reader },
]);

const form = useForm({
    name: '',
    email: '',
    role: 'reader' as App.Enums.UserRole,
    password: '',
});

function addUser(): void {
    form.post(UserController.store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Users" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Users"
            description="Admins change everything; readers can see everything and change nothing. The last admin can't be removed."
        />

        <DataTable
            :url="UserController.index()"
            :columns="columns"
            :rows="rows"
            :filters="filters"
            :sort="sort"
            :chips="chips"
            chip-filter="role"
            chips-label="Filter by role"
            empty-title="No users match"
            :row-key="(row) => row.id"
        />

        <section
            class="rounded-xl border bg-card p-6"
            aria-labelledby="add-user"
        >
            <h2 id="add-user" class="text-lg font-semibold">Add user</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Set a first password and give it to the person; they can change
                it under Settings.
            </p>
            <form
                class="mt-4 grid items-start gap-4 md:grid-cols-[1fr_1fr_auto_1fr_auto]"
                novalidate
                @submit.prevent="addUser"
            >
                <FormField
                    label="Name"
                    for="new-name"
                    :error="form.errors.name"
                >
                    <Input
                        id="new-name"
                        v-model="form.name"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    label="Email"
                    for="new-email"
                    :error="form.errors.email"
                >
                    <Input
                        id="new-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                    />
                </FormField>
                <FormField
                    label="Role"
                    for="new-role"
                    :error="form.errors.role"
                >
                    <select
                        id="new-role"
                        v-model="form.role"
                        :class="selectClasses"
                    >
                        <option value="reader">Reader</option>
                        <option value="admin">Admin</option>
                    </select>
                </FormField>
                <FormField
                    label="First password"
                    for="new-password"
                    :error="form.errors.password"
                >
                    <PasswordInput
                        id="new-password"
                        v-model="form.password"
                        autocomplete="new-password"
                        :passwordrules="password_rules"
                    />
                </FormField>
                <Button
                    type="submit"
                    class="md:mt-6"
                    :disabled="form.processing"
                    >Add user</Button
                >
            </form>
        </section>

        <ConfirmDialog
            v-model:open="confirmingDelete"
            :title="`Delete ${deleting?.name ?? ''}?`"
            :processing="processing"
            @confirm="destroy"
        >
            They can no longer sign in. Their past changes keep their name.
            <template v-if="deleting?.is_you">
                You will be signed out.</template
            >
        </ConfirmDialog>
    </div>
</template>
