<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import MonitorArgumentsForm from '@/components/app/MonitorArgumentsForm.vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type { ArgumentOverrides } from '@/lib/monitors';

/*
 * Add or edit a monitor (SPEC §3.8, §4.6). Empty fields inherit the
 * registry value, shown as the placeholder.
 */
const props = defineProps<{
    resource: { type: string; id: number };
    checkerOptions: App.Data.Monitors.CheckerOptionData[];
    /** Set when editing. */
    monitor?: App.Data.Monitors.MonitorFormData | null;
}>();

const emit = defineEmits<{ saved: [] }>();

const open = defineModel<boolean>('open', { required: true });

const blank = () => ({
    checker: props.monitor?.checker ?? props.checkerOptions[0]?.key ?? '',
    label: props.monitor?.label ?? '',
    arguments: { ...props.monitor?.arguments } as ArgumentOverrides,
    interval_minutes: props.monitor?.interval_minutes?.toString() ?? '',
    failures_before_incident:
        props.monitor?.failures_before_incident?.toString() ?? '',
    successes_before_resolve:
        props.monitor?.successes_before_resolve?.toString() ?? '',
    enabled: props.monitor?.enabled ?? true,
});

const form = useForm(blank());

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank());
        form.reset();
        form.clearErrors();
    }
});

const editing = computed(() => props.monitor?.id != null);

const option = computed(() =>
    props.checkerOptions.find((checker) => checker.key === form.checker),
);

watch(
    () => form.checker,
    () => {
        if (!editing.value) {
            form.arguments = {};
        }
    },
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('saved');
        },
    };

    form.transform((data) => ({
        ...data,
        monitorable_type: props.resource.type,
        monitorable_id: props.resource.id,
    }));

    if (editing.value && props.monitor?.id) {
        form.put(MonitorController.update(props.monitor.id).url, options);
    } else {
        form.post(MonitorController.store().url, options);
    }
}

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Edit monitor' : 'Add monitor'
                }}</DialogTitle>
                <DialogDescription>
                    Empty fields use the default, shown in grey. A change runs
                    the check right away.
                </DialogDescription>
            </DialogHeader>

            <form
                id="monitor-form"
                class="flex flex-col gap-5"
                novalidate
                @submit.prevent="submit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="monitor-checker">Check</Label>
                        <select
                            id="monitor-checker"
                            v-model="form.checker"
                            :class="fieldClasses"
                            :disabled="editing"
                        >
                            <option
                                v-for="checker in checkerOptions"
                                :key="checker.key"
                                :value="checker.key"
                            >
                                {{ checker.name }}
                            </option>
                        </select>
                        <InputError :message="errors.checker" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="monitor-label">Label</Label>
                        <Input
                            id="monitor-label"
                            v-model="form.label"
                            placeholder="e.g. SSH or Shop"
                            maxlength="100"
                        />
                        <p class="text-xs text-muted-foreground">
                            Tells apart two checks of the same kind.
                        </p>
                        <InputError :message="errors.label" />
                    </div>
                </div>

                <MonitorArgumentsForm
                    v-if="option && option.arguments.length > 0"
                    v-model="form.arguments"
                    :schema="option.arguments"
                    :errors="errors"
                    id-prefix="monitor-argument"
                />

                <fieldset class="grid gap-4 border-t pt-4 sm:grid-cols-3">
                    <legend class="sr-only">Timing and thresholds</legend>
                    <div class="grid content-start gap-1.5">
                        <Label for="monitor-interval">Every (minutes)</Label>
                        <Input
                            id="monitor-interval"
                            v-model="form.interval_minutes"
                            type="number"
                            min="1"
                            :placeholder="option ? String(option.interval) : ''"
                        />
                        <InputError :message="errors.interval_minutes" />
                    </div>
                    <div class="grid content-start gap-1.5">
                        <Label for="monitor-failures"
                            >Failures before an incident</Label
                        >
                        <Input
                            id="monitor-failures"
                            v-model="form.failures_before_incident"
                            type="number"
                            min="1"
                            :placeholder="
                                option
                                    ? String(option.failures_before_incident)
                                    : ''
                            "
                        />
                        <InputError
                            :message="errors.failures_before_incident"
                        />
                    </div>
                    <div class="grid content-start gap-1.5">
                        <Label for="monitor-successes"
                            >Passes before it resolves</Label
                        >
                        <Input
                            id="monitor-successes"
                            v-model="form.successes_before_resolve"
                            type="number"
                            min="1"
                            :placeholder="
                                option
                                    ? String(option.successes_before_resolve)
                                    : ''
                            "
                        />
                        <InputError
                            :message="errors.successes_before_resolve"
                        />
                    </div>
                </fieldset>

                <label class="flex items-center gap-3 text-sm">
                    <Switch v-model="form.enabled" />
                    Enabled
                    <span class="text-muted-foreground">
                        A disabled monitor doesn't run and doesn't count towards
                        the status.
                    </span>
                </label>
            </form>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    type="submit"
                    form="monitor-form"
                    :disabled="form.processing"
                >
                    {{ editing ? 'Save monitor' : 'Add monitor' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
