<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckerSettingController from '@/actions/App/Http/Controllers/CheckerSettingController';
import MonitorArgumentsForm from '@/components/app/MonitorArgumentsForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ArgumentOverrides } from '@/lib/monitors';

/*
 * One checker's defaults on the Monitor defaults page. Empty fields keep the
 * config value, shown as the placeholder; a monitor's own overrides still
 * win over these.
 */
const props = defineProps<{
    checker: App.Data.Monitors.CheckerDefaultsData;
}>();

const values = () => ({
    arguments: { ...props.checker.overrides } as ArgumentOverrides,
    interval_minutes: props.checker.interval_minutes?.toString() ?? '',
    failures_before_incident:
        props.checker.failures_before_incident_override?.toString() ?? '',
    successes_before_resolve:
        props.checker.successes_before_resolve_override?.toString() ?? '',
});

const form = useForm(values());

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const isCustomised = computed(
    () =>
        Object.keys(props.checker.overrides).length > 0 ||
        props.checker.interval_minutes !== null ||
        props.checker.failures_before_incident_override !== null ||
        props.checker.successes_before_resolve_override !== null,
);

const id = (field: string) => `${props.checker.key}-${field}`;

function submit(): void {
    form.put(CheckerSettingController.update(props.checker.key).url, {
        preserveScroll: true,
        // The saved values are the new baseline, so Save greys out again.
        onSuccess: () => {
            form.defaults(values());
            form.reset();
        },
    });
}

function resetToConfig(): void {
    form.arguments = {};
    form.interval_minutes = '';
    form.failures_before_incident = '';
    form.successes_before_resolve = '';
    submit();
}
</script>

<template>
    <form
        class="flex flex-col gap-5 rounded-xl border bg-card p-5"
        novalidate
        @submit.prevent="submit"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold">
                {{ checker.name }}
                <span class="ml-1 font-mono text-xs text-muted-foreground">{{
                    checker.key
                }}</span>
            </h3>
            <span
                v-if="isCustomised"
                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                >Customised</span
            >
        </div>

        <MonitorArgumentsForm
            v-if="checker.arguments.length > 0"
            v-model="form.arguments"
            :schema="checker.arguments"
            :errors="errors"
            :id-prefix="id('argument')"
        />

        <fieldset class="grid gap-4 border-t pt-4 sm:grid-cols-3">
            <legend class="sr-only">Timing and thresholds</legend>
            <div class="grid content-start gap-1.5">
                <Label :for="id('interval')">Every (minutes)</Label>
                <Input
                    :id="id('interval')"
                    v-model="form.interval_minutes"
                    type="number"
                    min="1"
                    :placeholder="String(checker.interval)"
                />
                <InputError :message="errors.interval_minutes" />
            </div>
            <div class="grid content-start gap-1.5">
                <Label :for="id('failures')">Failures before an incident</Label>
                <Input
                    :id="id('failures')"
                    v-model="form.failures_before_incident"
                    type="number"
                    min="1"
                    :placeholder="String(checker.failures_before_incident)"
                />
                <InputError :message="errors.failures_before_incident" />
            </div>
            <div class="grid content-start gap-1.5">
                <Label :for="id('successes')">Passes before it resolves</Label>
                <Input
                    :id="id('successes')"
                    v-model="form.successes_before_resolve"
                    type="number"
                    min="1"
                    :placeholder="String(checker.successes_before_resolve)"
                />
                <InputError :message="errors.successes_before_resolve" />
            </div>
        </fieldset>

        <div class="flex flex-wrap justify-end gap-2">
            <Button
                v-if="isCustomised"
                type="button"
                variant="outline"
                :disabled="form.processing"
                @click="resetToConfig"
                >Reset to config</Button
            >
            <Button type="submit" :disabled="form.processing || !form.isDirty"
                >Save</Button
            >
        </div>
    </form>
</template>
