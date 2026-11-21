<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import FormField from '@/components/app/FormField.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/* Add / edit application (SPEC §3.19). Sites join it from their own form or Import. */
const props = defineProps<{
    form: App.Data.ApplicationFormData;
}>();

const id = props.form.id;

const form = useForm({
    name: props.form.name,
    description: props.form.description ?? '',
    github_url: props.form.github_url ?? '',
    notes: props.form.notes ?? '',
});

const title = id === null ? 'Add application' : `Edit ${props.form.name}`;

const breadcrumbs = [
    { title: 'Applications', href: ApplicationController.index() },
    id === null
        ? { title: 'Add application', href: ApplicationController.create() }
        : { title: props.form.name, href: ApplicationController.show(id) },
];

// Bound from here: vue-tsc misreads a literal "https://" in a template attribute.
const githubPlaceholder = 'https://github.com/media24/backend';

function submit(): void {
    if (id === null) {
        form.post(ApplicationController.store().url);
    } else {
        form.put(ApplicationController.update(id).url);
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
            description="One web app behind one or more hostnames. Add its hostnames from the site form or the Import page."
        />

        <form
            class="flex flex-col gap-5 rounded-xl border bg-card p-6"
            novalidate
            @submit.prevent="submit"
        >
            <FormField
                label="Name"
                for="name"
                :error="form.errors.name"
                required
            >
                <Input
                    id="name"
                    v-model="form.name"
                    placeholder="Media24 backend"
                    autocomplete="off"
                    :aria-invalid="!!form.errors.name"
                />
            </FormField>
            <FormField
                label="Description"
                for="description"
                :error="form.errors.description"
            >
                <Input id="description" v-model="form.description" />
            </FormField>
            <FormField
                label="GitHub repository"
                for="github_url"
                :error="form.errors.github_url"
            >
                <Input
                    id="github_url"
                    v-model="form.github_url"
                    class="font-mono"
                    :placeholder="githubPlaceholder"
                    :aria-invalid="!!form.errors.github_url"
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
            <div class="flex justify-end gap-3 border-t pt-5">
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            id === null
                                ? ApplicationController.index()
                                : ApplicationController.show(id)
                        "
                        >Cancel</Link
                    >
                </Button>
                <Button type="submit" :disabled="form.processing"
                    >Save application</Button
                >
            </div>
        </form>
    </div>
</template>
