<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { externalServiceKindLabels, rangeSourceLabels } from '@/lib/labels';

/* Add / edit external service (SPEC §3.5, P1.13). */
const props = defineProps<{
    form: App.Data.ExternalServiceFormData;
}>();

const id = props.form.id;

// Lists are edited one per line and sent as arrays.
const form = useForm({
    name: props.form.name,
    kind: props.form.kind,
    ranges: props.form.ranges.join('\n'),
    ranges_source: props.form.ranges_source,
    cname_suffixes: props.form.cname_suffixes.join('\n'),
    response_headers: props.form.response_headers.join('\n'),
    notes: props.form.notes ?? '',
});

const title = id === null ? 'Add external service' : `Edit ${props.form.name}`;

const breadcrumbs = [
    { title: 'External services', href: ExternalServiceController.index() },
    {
        title: id === null ? 'Add' : props.form.name,
        href:
            id === null
                ? ExternalServiceController.create()
                : ExternalServiceController.edit(id),
    },
];

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';

/** The first error of a list field or any of its items. */
function listError(field: string): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors[field] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}

const lineList = (text: string) =>
    text
        .split(/[\n,]+/)
        .map((line) => line.trim())
        .filter((line) => line !== '');

function submit(): void {
    form.transform((data) => ({
        ...data,
        ranges: lineList(data.ranges),
        cname_suffixes: lineList(data.cname_suffixes),
        response_headers: lineList(data.response_headers),
    }));

    if (id === null) {
        form.post(ExternalServiceController.store().url, {
            preserveScroll: true,
        });
    } else {
        form.put(ExternalServiceController.update(id).url, {
            preserveScroll: true,
        });
    }
}

const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy(): void {
    if (id === null) {
        return;
    }

    router.delete(ExternalServiceController.destroy(id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}

const kinds = computed(
    () =>
        Object.entries(externalServiceKindLabels) as [
            App.Enums.ExternalServiceKind,
            string,
        ][],
);
const sources = computed(
    () =>
        Object.entries(rangeSourceLabels) as [App.Enums.RangeSource, string][],
);
</script>

<template>
    <Head :title="title" />

    <div
        class="flex w-full max-w-[880px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            :title="title"
            description="Sites behind this service are matched by its addresses, CNAME suffixes or response headers. Our own servers go under Servers."
        />

        <form
            class="flex flex-col gap-5 rounded-xl border bg-card p-6"
            novalidate
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Name"
                    for="name"
                    :error="form.errors.name"
                    required
                >
                    <Input
                        id="name"
                        v-model="form.name"
                        placeholder="Varnish"
                        :aria-invalid="!!form.errors.name"
                    />
                </FormField>
                <FormField label="Kind" for="kind" :error="form.errors.kind">
                    <select id="kind" v-model="form.kind" :class="fieldClasses">
                        <option
                            v-for="[value, label] in kinds"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </FormField>
            </div>

            <FormField
                label="Addresses and ranges"
                for="ranges"
                :error="listError('ranges')"
                description="One IP address or CIDR range per line, e.g. the Varnish server's addresses."
            >
                <textarea
                    id="ranges"
                    v-model="form.ranges"
                    rows="4"
                    :class="[fieldClasses, 'font-mono']"
                    placeholder="203.0.113.80&#10;2001:db8:80::/48"
                    :aria-invalid="!!listError('ranges')"
                />
            </FormField>

            <FormField
                label="Published ranges"
                for="ranges_source"
                :error="form.errors.ranges_source"
                description="For CDNs that publish their IP ranges. Sextant downloads them daily and adds them to the list above."
            >
                <select
                    id="ranges_source"
                    v-model="form.ranges_source"
                    :class="fieldClasses"
                >
                    <option :value="null">None</option>
                    <option
                        v-for="[value, label] in sources"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="CNAME suffixes"
                    for="cname_suffixes"
                    :error="listError('cname_suffixes')"
                    description="One per line, e.g. .cdn.cloudflare.net"
                >
                    <textarea
                        id="cname_suffixes"
                        v-model="form.cname_suffixes"
                        rows="3"
                        :class="[fieldClasses, 'font-mono']"
                        :aria-invalid="!!listError('cname_suffixes')"
                    />
                </FormField>
                <FormField
                    label="Response headers"
                    for="response_headers"
                    :error="listError('response_headers')"
                    description="Header names that reveal the service, e.g. cf-ray"
                >
                    <textarea
                        id="response_headers"
                        v-model="form.response_headers"
                        rows="3"
                        :class="[fieldClasses, 'font-mono']"
                        :aria-invalid="!!listError('response_headers')"
                    />
                </FormField>
            </div>

            <FormField label="Notes" for="notes" :error="form.errors.notes">
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="3"
                    :class="fieldClasses"
                />
            </FormField>

            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t pt-5"
            >
                <Button
                    v-if="id !== null"
                    type="button"
                    variant="outline"
                    class="text-destructive"
                    @click="confirmingDelete = true"
                >
                    Delete
                </Button>
                <span v-else />
                <div class="flex gap-3">
                    <Button variant="outline" as-child>
                        <Link :href="ExternalServiceController.index()"
                            >Cancel</Link
                        >
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Save external service
                    </Button>
                </div>
            </div>
        </form>

        <ConfirmDialog
            v-model:open="confirmingDelete"
            :title="`Delete ${props.form.name}?`"
            :processing="deleting"
            @confirm="destroy"
        >
            It leaves the list and stops matching sites. A service that is in
            front of sites can't be deleted.
        </ConfirmDialog>
    </div>
</template>
