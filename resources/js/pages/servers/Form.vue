<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/* Add / edit server (SPEC §13.6, P1.12). */
const props = defineProps<{
    form: App.Data.ServerFormData;
    os_choices: App.Data.ChoiceData[];
    backup_choices: App.Data.ChoiceData[];
}>();

const editing = computed(() => props.form.id !== null);

const form = useForm({
    name: props.form.name,
    hostname: props.form.hostname ?? '',
    provider: props.form.provider ?? '',
    os_choice_id: props.form.os_choice_id,
    notes: props.form.notes ?? '',
    addresses: props.form.addresses.map((address) => ({ ...address })),
    backup_location_ids: [...props.form.backup_location_ids],
});

const title = computed(() =>
    editing.value ? `Edit ${props.form.name}` : 'Add server',
);

const breadcrumbs = computed(() => [
    { title: 'Servers', href: ServerController.index() },
    editing.value && props.form.id !== null
        ? {
              title: props.form.name,
              href: ServerController.show(props.form.id),
          }
        : { title: 'Add server', href: ServerController.create() },
    ...(editing.value && props.form.id !== null
        ? [{ title: 'Edit', href: ServerController.edit(props.form.id) }]
        : []),
]);

const cancelHref = computed(() =>
    props.form.id !== null
        ? ServerController.show(props.form.id)
        : ServerController.index(),
);

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';

function ipVersion(ip: string): string {
    if (ip.trim() === '') {
        return '';
    }

    return ip.includes(':') ? 'IPv6' : 'IPv4';
}

function addAddress(): void {
    form.addresses.push({ ip: '', is_primary: form.addresses.length === 0 });
}

function removeAddress(index: number): void {
    const wasPrimary = form.addresses[index].is_primary;
    form.addresses.splice(index, 1);

    if (wasPrimary && form.addresses.length > 0) {
        form.addresses[0].is_primary = true;
    }
}

function makePrimary(index: number): void {
    form.addresses.forEach((address, i) => (address.is_primary = i === index));
}

function toggleBackup(id: number, checked: boolean | 'indeterminate'): void {
    form.backup_location_ids =
        checked === true
            ? [...form.backup_location_ids, id]
            : form.backup_location_ids.filter((existing) => existing !== id);
}

function addressError(index: number): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors[`addresses.${index}.ip`] ??
        errors[`addresses.${index}.is_primary`]
    );
}

const primaryIp = computed(
    () => form.addresses.find((address) => address.is_primary)?.ip || '—',
);
const osLabel = computed(
    () =>
        props.os_choices.find((choice) => choice.id === form.os_choice_id)
            ?.label ?? 'Not set',
);
const backupLabels = computed(() => {
    const labels = props.backup_choices
        .filter((choice) => form.backup_location_ids.includes(choice.id))
        .map((choice) => choice.label);

    return labels.length > 0 ? labels.join(', ') : 'None set';
});

function submit(): void {
    if (props.form.id !== null) {
        form.put(ServerController.update(props.form.id).url, {
            preserveScroll: true,
        });
    } else {
        form.post(ServerController.store().url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="title" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            :title="title"
            description="Only our own servers go here. Cloudflare, Varnish and other external services are added under External services."
        />

        <form
            class="grid items-start gap-6 lg:grid-cols-[2fr_1fr]"
            novalidate
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-6">
                <section
                    class="flex flex-col gap-5 rounded-xl border bg-card p-6"
                    aria-labelledby="basics"
                >
                    <h2 id="basics" class="text-lg font-semibold">
                        <span class="text-muted-foreground">1</span> Basics
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField
                            label="Name"
                            for="name"
                            :error="form.errors.name"
                            required
                        >
                            <Input
                                id="name"
                                v-model="form.name"
                                class="font-mono"
                                placeholder="web06"
                                autocomplete="off"
                                :aria-invalid="!!form.errors.name"
                            />
                        </FormField>
                        <FormField
                            label="Hostname"
                            for="hostname"
                            :error="form.errors.hostname"
                        >
                            <Input
                                id="hostname"
                                v-model="form.hostname"
                                class="font-mono"
                                placeholder="web06.media24.si"
                                autocomplete="off"
                                :aria-invalid="!!form.errors.hostname"
                            />
                        </FormField>
                        <FormField
                            label="Provider"
                            for="provider"
                            :error="form.errors.provider"
                        >
                            <Input
                                id="provider"
                                v-model="form.provider"
                                placeholder="Hetzner"
                                :aria-invalid="!!form.errors.provider"
                            />
                        </FormField>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField
                            label="Operating system"
                            for="os_choice_id"
                            :error="form.errors.os_choice_id"
                            description="Also read from the versions endpoint once it's set up."
                        >
                            <select
                                id="os_choice_id"
                                v-model="form.os_choice_id"
                                :class="fieldClasses"
                                :aria-invalid="!!form.errors.os_choice_id"
                            >
                                <option :value="null">Not set</option>
                                <option
                                    v-for="choice in os_choices"
                                    :key="choice.id"
                                    :value="choice.id"
                                >
                                    {{ choice.label }}
                                </option>
                            </select>
                        </FormField>
                    </div>

                    <fieldset class="rounded-lg border px-4 pt-2 pb-4">
                        <legend class="px-2 text-sm font-medium">
                            Backups stored on
                        </legend>
                        <div class="grid gap-3 pt-2 sm:grid-cols-3">
                            <div
                                v-for="choice in backup_choices"
                                :key="choice.id"
                                class="flex items-center gap-2.5"
                            >
                                <Checkbox
                                    :id="`backup-${choice.id}`"
                                    :model-value="
                                        form.backup_location_ids.includes(
                                            choice.id,
                                        )
                                    "
                                    @update:model-value="
                                        toggleBackup(choice.id, $event)
                                    "
                                />
                                <Label
                                    :for="`backup-${choice.id}`"
                                    class="font-normal"
                                    >{{ choice.label }}</Label
                                >
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Admins manage this list under Lists.
                        </p>
                        <InputError
                            :message="form.errors.backup_location_ids"
                        />
                    </fieldset>

                    <FormField
                        label="Notes"
                        for="notes"
                        :error="form.errors.notes"
                    >
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            :class="fieldClasses"
                            placeholder="Anything the team should know"
                        />
                    </FormField>
                </section>

                <section
                    class="flex flex-col gap-4 rounded-xl border bg-card p-6"
                    aria-labelledby="addresses"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2 id="addresses" class="text-lg font-semibold">
                            <span class="text-muted-foreground">2</span> IP
                            addresses
                        </h2>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="add-address"
                            @click="addAddress"
                        >
                            + Add address
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Every address the server answers on. Sextant uses them
                        to match sites to this server; checks target the primary
                        one.
                    </p>
                    <InputError :message="form.errors.addresses" />

                    <fieldset class="flex flex-col gap-3">
                        <legend class="sr-only">Addresses</legend>
                        <div
                            v-for="(address, index) in form.addresses"
                            :key="index"
                            class="flex flex-col gap-1 rounded-lg border px-4 py-3"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <Label :for="`address-${index}`" class="sr-only"
                                    >IP address {{ index + 1 }}</Label
                                >
                                <Input
                                    :id="`address-${index}`"
                                    v-model="address.ip"
                                    class="min-w-48 flex-1 font-mono"
                                    placeholder="198.51.100.23"
                                    autocomplete="off"
                                    :aria-invalid="!!addressError(index)"
                                />
                                <span
                                    class="w-10 text-xs text-muted-foreground"
                                    >{{ ipVersion(address.ip) }}</span
                                >
                                <label class="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="primary_address"
                                        class="size-4 accent-primary"
                                        :checked="address.is_primary"
                                        @change="makePrimary(index)"
                                    />
                                    Primary
                                </label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                    :disabled="form.addresses.length === 1"
                                    @click="removeAddress(index)"
                                >
                                    Remove
                                </Button>
                            </div>
                            <InputError :message="addressError(index)" />
                        </div>
                    </fieldset>
                </section>

                <section
                    class="flex flex-col gap-2 rounded-xl border border-dashed bg-card p-6 text-muted-foreground"
                    aria-labelledby="versions"
                >
                    <h2
                        id="versions"
                        class="text-lg font-semibold text-foreground"
                    >
                        <span class="text-muted-foreground">3</span> Software
                        versions
                    </h2>
                    <p class="text-sm">
                        Not available yet. Once the versions collector arrives,
                        this is where you generate the token, download the two
                        files and set the endpoint URL.
                    </p>
                </section>

                <div class="flex justify-end gap-3">
                    <Button variant="outline" as-child>
                        <Link :href="cancelHref">Cancel</Link>
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="save-server"
                    >
                        Save server
                    </Button>
                </div>
            </div>

            <aside
                class="rounded-xl border bg-card p-6 lg:sticky lg:top-6"
                aria-labelledby="summary"
            >
                <h2 id="summary" class="text-lg font-semibold">Summary</h2>
                <dl
                    class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm"
                >
                    <dt class="text-muted-foreground">Name</dt>
                    <dd class="text-right font-mono">{{ form.name || '—' }}</dd>
                    <dt class="text-muted-foreground">Addresses</dt>
                    <dd class="text-right">
                        {{
                            form.addresses.filter(
                                (address) => address.ip.trim() !== '',
                            ).length
                        }}
                    </dd>
                    <dt class="text-muted-foreground">Primary</dt>
                    <dd class="text-right font-mono">{{ primaryIp }}</dd>
                    <dt class="text-muted-foreground">OS</dt>
                    <dd class="text-right">{{ osLabel }}</dd>
                    <dt class="text-muted-foreground">Backups</dt>
                    <dd
                        class="text-right"
                        :class="
                            form.backup_location_ids.length === 0
                                ? 'font-semibold text-orange-700 dark:text-orange-400'
                                : ''
                        "
                    >
                        {{ backupLabels }}
                    </dd>
                </dl>
                <p class="mt-5 border-t pt-4 text-xs text-muted-foreground">
                    Sites aren't assigned here. Add a site and pick this server
                    as its backend; the server page then lists every site that
                    reaches it.
                </p>
            </aside>
        </form>
    </div>
</template>
