<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/*
 * Add / edit domain by hand (Phase 1). Phase 2's Add domain (SPEC §10.5)
 * adds the registry lookup and subdomains.
 */
const props = defineProps<{
    form: App.Data.DomainFormData;
}>();

const id = props.form.id;

const form = useForm({
    name: props.form.name,
    notes: props.form.notes ?? '',
});

const title = id === null ? 'Add domain' : `Edit ${props.form.name}`;

const breadcrumbs = [
    { title: 'Domains', href: DomainController.index() },
    id === null
        ? { title: 'Add domain', href: DomainController.create() }
        : { title: props.form.name, href: DomainController.show(id) },
];

function submit(): void {
    if (id === null) {
        form.post(DomainController.store().url);
    } else {
        form.put(DomainController.update(id).url);
    }
}
</script>

<template>
    <Head :title="title" />

    <div
        class="flex w-full max-w-[720px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <PageHeader
            :title="title"
            description="The registrable domain we renew, such as svet24.si. Its subdomains are added as sites."
        />

        <form
            class="flex flex-col gap-5 rounded-xl border bg-card p-6"
            novalidate
            @submit.prevent="submit"
        >
            <FormField
                label="Domain"
                for="name"
                :error="form.errors.name"
                :description="
                    props.form.sites > 0
                        ? `Its ${props.form.sites === 1 ? 'site is' : `${props.form.sites} sites are`} under this name, so it can't change. Add the new domain instead.`
                        : undefined
                "
                required
            >
                <Input
                    id="name"
                    v-model="form.name"
                    class="font-mono"
                    placeholder="svet24.si"
                    autocomplete="off"
                    :readonly="props.form.sites > 0"
                    :aria-invalid="!!form.errors.name"
                />
            </FormField>
            <FormField label="Notes" for="notes" :error="form.errors.notes">
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="3"
                    class="w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                />
            </FormField>
            <p class="text-sm text-muted-foreground">
                Expiry and registrar are read from the registry once the expiry
                check runs.
            </p>
            <div class="flex justify-end gap-3 border-t pt-5">
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            id === null
                                ? DomainController.index()
                                : DomainController.show(id)
                        "
                        >Cancel</Link
                    >
                </Button>
                <Button type="submit" :disabled="form.processing"
                    >Save domain</Button
                >
            </div>
        </form>
    </div>
</template>
