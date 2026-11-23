<script setup lang="ts">
import { Head, router, useForm, useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import GithubAccessController from '@/actions/App/Http/Controllers/GithubAccessController';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/datetime';

/*
 * GitHub access (SPEC §11.9, §13.6): one read-only fine-grained token per
 * owner of a linked repository. Sextant reads only the lock files in the
 * root of each repository's default branch. Tokens never come back to the
 * browser; Test lists which linked repositories a token can read.
 */
type Row = App.Data.GithubOwnerRowData;
type Result = App.Data.GithubTestResultData;

const props = defineProps<{
    owners: Row[];
    expiry_warning_days: number;
}>();

const now = Date.now();

const open = ref(false);
const editing = ref<Row | null>(null);
const removing = ref<Row | null>(null);

const tokenForm = useForm({ owner: '', token: '' });

function setToken(row: Row | null): void {
    editing.value = row;
    tokenForm.defaults({ owner: row?.owner ?? '', token: '' });
    tokenForm.reset();
    tokenForm.clearErrors();
    open.value = true;
}

function submit(): void {
    tokenForm.post(GithubAccessController.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            if (editing.value) {
                delete results.value[editing.value.owner];
            }
        },
        onFinish: () => tokenForm.reset('token'),
    });
}

function remove(): void {
    const row = removing.value;

    if (row?.token_id) {
        router.delete(GithubAccessController.destroy(row.token_id).url, {
            preserveScroll: true,
            onFinish: () => (removing.value = null),
        });
    }
}

const http = useHttp<Record<string, never>, Result>({});
const testing = ref<string | null>(null);
const results = ref<Record<string, Result>>({});

async function test(row: Row): Promise<void> {
    if (!row.token_id) {
        return;
    }

    testing.value = row.owner;
    delete results.value[row.owner];

    try {
        results.value[row.owner] = await http.post(
            GithubAccessController.test(row.token_id).url,
        );
    } catch {
        results.value[row.owner] = {
            ok: false,
            error: 'The request failed.',
            expires_at: null,
            repositories: [],
        };
    } finally {
        testing.value = null;
        router.reload({ only: ['owners'] });
    }
}

function expiresSoon(iso: string | null): boolean {
    return (
        iso !== null &&
        new Date(iso).getTime() - now <
            props.expiry_warning_days * 24 * 60 * 60 * 1000
    );
}
</script>

<template>
    <Head title="GitHub access" />

    <div
        class="flex w-full max-w-[1100px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="GitHub access"
            description="Read-only tokens Sextant uses to read composer.lock, package-lock.json and pnpm-lock.yaml from the repositories that sites and applications link to. One token per organisation or user."
        >
            <template #actions>
                <Button @click="setToken(null)">+ Add a token</Button>
            </template>
        </PageHeader>

        <section class="rounded-xl border bg-card p-5 text-sm">
            <h2 class="font-semibold">Creating a read-only token</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-muted-foreground">
                <li>
                    Sign in to GitHub as a member of the organisation, ideally a
                    dedicated account that only reads, so the token doesn't stop
                    working when someone leaves.
                </li>
                <li>
                    Open
                    <a
                        href="https://github.com/settings/personal-access-tokens/new"
                        target="_blank"
                        rel="noopener"
                        class="text-primary underline"
                        >Settings → Developer settings → Fine-grained tokens →
                        Generate new token</a
                    >.
                </li>
                <li>
                    <strong class="text-foreground">Resource owner</strong>: the
                    organisation (or user) below.
                    <strong class="text-foreground">Repository access</strong>:
                    Only select repositories, then pick the linked ones.
                </li>
                <li>
                    <strong class="text-foreground">Permissions</strong> →
                    Repository →
                    <strong class="text-foreground">Contents: Read-only</strong
                    >. GitHub adds Metadata: Read-only. Nothing else.
                </li>
                <li>
                    If the organisation approves tokens, an owner approves it
                    under Organisation settings → Personal access tokens.
                </li>
                <li>Paste it here, then press Test.</li>
            </ol>
        </section>

        <section class="overflow-x-auto rounded-xl border bg-card">
            <EmptyState
                v-if="owners.length === 0"
                title="No GitHub links yet"
                description="Add a GitHub link to an application or site and its owner shows here."
            />
            <table v-else class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-5 py-3 font-medium">Owner</th>
                        <th class="px-5 py-3 font-medium">Repositories</th>
                        <th class="px-5 py-3 font-medium">Token</th>
                        <th class="px-5 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template v-for="row in owners" :key="row.owner">
                        <tr class="align-top">
                            <td class="px-5 py-3 font-medium">
                                <a
                                    :href="`https://github.com/${row.owner}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="hover:underline"
                                    >{{ row.owner }}</a
                                >
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="row.repositories.length === 0"
                                    class="text-muted-foreground"
                                    >None linked</span
                                >
                                <ul v-else class="flex flex-col gap-0.5">
                                    <li
                                        v-for="name in row.repositories"
                                        :key="name"
                                        class="font-mono"
                                    >
                                        {{ name }}
                                    </li>
                                </ul>
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="!row.token_masked"
                                    class="text-status-warning-fg"
                                    >No token: lock files aren't read</span
                                >
                                <div v-else class="flex flex-col gap-0.5">
                                    <span class="font-mono">{{
                                        row.token_masked
                                    }}</span>
                                    <span
                                        v-if="row.expires_at"
                                        :class="
                                            expiresSoon(row.expires_at)
                                                ? 'font-semibold text-status-warning-fg'
                                                : 'text-muted-foreground'
                                        "
                                        :title="formatDateTime(row.expires_at)"
                                        >Expires
                                        {{
                                            formatRelative(row.expires_at, now)
                                        }}</span
                                    >
                                    <span
                                        v-if="row.last_error"
                                        class="text-destructive"
                                        >{{ row.last_error }}</span
                                    >
                                    <span
                                        v-else-if="row.checked_at"
                                        class="text-status-ok-fg"
                                        >Works ·
                                        {{
                                            formatRelative(row.checked_at, now)
                                        }}</span
                                    >
                                    <span class="text-muted-foreground"
                                        >{{ row.set_by }}
                                        <template v-if="row.updated_at"
                                            >·
                                            {{
                                                formatDateTime(row.updated_at)
                                            }}</template
                                        ></span
                                    >
                                </div>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <template v-if="row.token_id">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        :disabled="testing !== null"
                                        @click="test(row)"
                                    >
                                        <Spinner v-if="testing === row.owner" />
                                        Test
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="setToken(row)"
                                        >Replace</Button
                                    >
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="text-destructive"
                                        @click="removing = row"
                                        >Remove</Button
                                    >
                                </template>
                                <Button v-else size="sm" @click="setToken(row)"
                                    >Set token</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="results[row.owner]">
                            <td colspan="4" class="bg-muted/40 px-5 py-3">
                                <p
                                    v-if="results[row.owner].error"
                                    class="text-destructive"
                                    role="alert"
                                >
                                    {{ results[row.owner].error }}
                                </p>
                                <ul
                                    v-else
                                    class="flex flex-col gap-1"
                                    role="status"
                                >
                                    <li
                                        v-for="repo in results[row.owner]
                                            .repositories"
                                        :key="repo.name"
                                    >
                                        <span class="font-mono">{{
                                            repo.name
                                        }}</span>
                                        <span
                                            v-if="repo.ok"
                                            class="text-status-ok-fg"
                                        >
                                            · readable ({{
                                                repo.default_branch
                                            }}) ·
                                            {{
                                                repo.lock_files.length
                                                    ? repo.lock_files.join(', ')
                                                    : 'no lock file in the root'
                                            }}
                                        </span>
                                        <span v-else class="text-destructive">
                                            · {{ repo.error }}</span
                                        >
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </section>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing?.token_id
                            ? `Replace the token for ${editing.owner}`
                            : 'Add a GitHub token'
                    }}</DialogTitle>
                    <DialogDescription
                        >A fine-grained token with read-only access to Contents,
                        limited to the linked repositories.</DialogDescription
                    >
                </DialogHeader>
                <form
                    id="token-form"
                    class="flex flex-col gap-4"
                    novalidate
                    @submit.prevent="submit"
                >
                    <FormField
                        label="Owner"
                        for="owner"
                        :error="tokenForm.errors.owner"
                        description="The organisation or user, as in github.com/{owner}."
                        required
                    >
                        <Input
                            id="owner"
                            v-model="tokenForm.owner"
                            class="font-mono"
                            placeholder="media24"
                            :readonly="editing !== null"
                        />
                    </FormField>
                    <FormField
                        label="Token"
                        for="token"
                        :error="tokenForm.errors.token"
                        required
                    >
                        <Input
                            id="token"
                            v-model="tokenForm.token"
                            type="password"
                            autocomplete="off"
                            class="font-mono"
                            placeholder="github_pat_…"
                        />
                    </FormField>
                </form>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="open = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        form="token-form"
                        :disabled="tokenForm.processing"
                        >Save token</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Remove the token for ${removing?.owner}?`"
            confirm-label="Remove"
            @update:open="(value) => !value && (removing = null)"
            @confirm="remove"
        >
            Sextant stops reading lock files from its repositories. Revoke the
            token on GitHub too.
        </ConfirmDialog>
    </div>
</template>
