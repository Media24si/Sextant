<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
import { formatDateTime } from '@/lib/datetime';

/*
 * "Mute until…" (SPEC §6.6): no notifications and left out of the status
 * until then; checks keep running and incidents still open and resolve.
 */
const props = defineProps<{
    /** POST url of the mute route. */
    url: string;
    title: string;
    description: string;
    /** Set when something here is muted now; offers Unmute. */
    mutedUntil?: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

type Choice = '1h' | '4h' | 'morning' | 'custom';

const choice = ref<Choice>('1h');
const custom = ref('');
const error = ref<string | undefined>();
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        choice.value = '1h';
        custom.value = '';
        error.value = undefined;
    }
});

/** Tomorrow 08:00 in Ljubljana. */
function tomorrowMorning(): Date {
    const now = new Date();
    const ljubljana = new Date(
        now.toLocaleString('en-US', { timeZone: 'Europe/Ljubljana' }),
    );
    const offset = now.getTime() - ljubljana.getTime();
    const morning = new Date(ljubljana);

    morning.setDate(morning.getDate() + 1);
    morning.setHours(8, 0, 0, 0);

    return new Date(morning.getTime() + offset);
}

const until = computed<Date | null>(() => {
    const now = Date.now();

    switch (choice.value) {
        case '1h':
            return new Date(now + 3_600_000);
        case '4h':
            return new Date(now + 4 * 3_600_000);
        case 'morning':
            return tomorrowMorning();
        default:
            return custom.value === '' ? null : new Date(custom.value);
    }
});

function submit(value: Date | null): void {
    router.post(
        props.url,
        { until: value?.toISOString() ?? null },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onError: (errors) => (error.value = errors.until),
            onSuccess: () => (open.value = false),
        },
    );
}

const choices: { value: Choice; label: string }[] = [
    { value: '1h', label: '1 hour' },
    { value: '4h', label: '4 hours' },
    { value: 'morning', label: 'Until tomorrow 08:00' },
    { value: 'custom', label: 'Until…' },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <p v-if="mutedUntil" class="rounded-lg bg-muted/50 p-3 text-sm">
                Muted until {{ formatDateTime(mutedUntil) }}.
            </p>

            <fieldset class="flex flex-col gap-2 text-sm">
                <legend class="sr-only">Mute for</legend>
                <label
                    v-for="item in choices"
                    :key="item.value"
                    class="flex items-center gap-2"
                >
                    <input
                        v-model="choice"
                        type="radio"
                        name="mute-choice"
                        :value="item.value"
                        class="accent-primary"
                    />
                    {{ item.label }}
                </label>
                <Input
                    v-if="choice === 'custom'"
                    v-model="custom"
                    type="datetime-local"
                    aria-label="Mute until"
                />
                <InputError :message="error" />
            </fieldset>

            <DialogFooter class="gap-2">
                <Button
                    v-if="mutedUntil"
                    variant="outline"
                    :disabled="processing"
                    @click="submit(null)"
                    >Unmute</Button
                >
                <Button
                    :disabled="processing || until === null"
                    @click="submit(until)"
                    >Mute</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
