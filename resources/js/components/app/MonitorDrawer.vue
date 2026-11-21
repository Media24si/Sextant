<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import MonitorBadges from '@/components/app/MonitorBadges.vue';
import MonitorFormDialog from '@/components/app/MonitorFormDialog.vue';
import MuteDialog from '@/components/app/MuteDialog.vue';
import IncidentController from '@/actions/App/Http/Controllers/IncidentController';
import MuteController from '@/actions/App/Http/Controllers/MuteController';
import RelativeTime from '@/components/app/RelativeTime.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { everyLabel } from '@/lib/monitors';

/*
 * A monitor's effective settings, state and last 100 runs (SPEC §13.6),
 * with Run now, Edit and Delete for admins.
 */
const props = defineProps<{
    monitorId: number | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const page = usePage();
const detail = ref<App.Data.Monitors.MonitorDetailData | null>(null);
const loading = ref(false);
const editing = ref(false);
const confirmingDelete = ref(false);
const muting = ref(false);

async function load(): Promise<void> {
    if (props.monitorId === null) {
        return;
    }

    loading.value = detail.value?.monitor.id !== props.monitorId;

    try {
        const response = await fetch(
            MonitorController.show(props.monitorId).url,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        detail.value = response.ok ? await response.json() : null;
    } finally {
        loading.value = false;
    }
}

watch([open, () => props.monitorId], ([isOpen]) => {
    if (isOpen) {
        void load();
    }
});

/** After an action, reload the page's monitors and this drawer. */
function refresh(): void {
    router.reload({ only: ['monitors', 'rows'] });
    void load();
}

function runNow(): void {
    if (!detail.value) {
        return;
    }

    router.post(
        MonitorController.run(detail.value.monitor.id).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                // Queued: give the worker a moment before reloading.
                window.setTimeout(refresh, 3000);
            },
        },
    );
}

function destroy(): void {
    if (!detail.value) {
        return;
    }

    router.delete(MonitorController.destroy(detail.value.monitor.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmingDelete.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <div
                v-if="loading || !detail"
                class="flex items-center gap-2 p-6 text-sm text-muted-foreground"
            >
                <Spinner /> Loading
            </div>

            <template v-else>
                <SheetHeader class="gap-2 border-b">
                    <SheetTitle class="flex flex-wrap items-center gap-2 pr-8">
                        {{ detail.monitor.name }}
                        <StatusBadge :status="detail.monitor.status" />
                        <MonitorBadges :monitor="detail.monitor" />
                    </SheetTitle>
                    <SheetDescription>
                        On
                        <span class="font-mono">{{
                            detail.monitor.resource.name
                        }}</span>
                        · {{ everyLabel(detail.monitor.interval) }}
                    </SheetDescription>
                    <div
                        v-if="page.props.can.manage"
                        class="flex flex-wrap gap-2 pt-1"
                    >
                        <Button
                            size="sm"
                            :disabled="!detail.monitor.enabled"
                            @click="runNow"
                            >Run now</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="editing = true"
                            >Edit</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            class="text-destructive"
                            @click="confirmingDelete = true"
                            >Delete</Button
                        >
                    </div>
                </SheetHeader>

                <div class="flex flex-col gap-6 px-4 pb-8 text-sm">
                    <section
                        aria-labelledby="drawer-latest"
                        class="flex flex-col gap-1"
                    >
                        <h3 id="drawer-latest" class="font-semibold">
                            Latest result
                        </h3>
                        <p
                            v-if="detail.monitor.checked_at"
                            class="flex items-start gap-2"
                        >
                            <StatusDot
                                v-if="detail.monitor.last_result_status"
                                :status="detail.monitor.last_result_status"
                                label
                                class="mt-1.5"
                            />
                            <span>
                                {{ detail.monitor.message }}
                                <span class="text-muted-foreground">
                                    ·
                                    <RelativeTime
                                        :datetime="detail.monitor.checked_at"
                                    />
                                </span>
                            </span>
                        </p>
                        <p v-else class="text-muted-foreground">
                            Not checked yet.
                        </p>
                        <p
                            v-if="detail.monitor.next_run_at"
                            class="text-muted-foreground"
                        >
                            Next run
                            <RelativeTime
                                :datetime="detail.monitor.next_run_at"
                            />
                        </p>
                        <p class="text-muted-foreground">
                            Streak: {{ detail.consecutive_failures }} failures,
                            {{ detail.consecutive_successes }} passes,
                            {{ detail.consecutive_unknowns }} unknown
                        </p>
                    </section>

                    <section aria-labelledby="drawer-settings">
                        <h3 id="drawer-settings" class="mb-2 font-semibold">
                            Settings
                        </h3>
                        <dl class="divide-y rounded-lg border">
                            <div
                                v-for="setting in detail.settings"
                                :key="setting.label"
                                class="flex justify-between gap-4 px-3 py-2"
                            >
                                <dt class="text-muted-foreground">
                                    {{ setting.label }}
                                </dt>
                                <dd
                                    class="text-right font-mono break-all"
                                    :class="{
                                        'text-muted-foreground':
                                            setting.inherited,
                                    }"
                                    :title="
                                        setting.inherited
                                            ? 'Default from the registry'
                                            : 'Set on this monitor'
                                    "
                                >
                                    {{ setting.value }}
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Grey values are defaults; black ones are set on this
                            monitor.
                        </p>
                    </section>

                    <section aria-labelledby="drawer-runs">
                        <h3 id="drawer-runs" class="mb-2 font-semibold">
                            Last {{ detail.runs.length }} runs
                        </h3>
                        <p
                            v-if="detail.runs.length === 0"
                            class="text-muted-foreground"
                        >
                            No runs yet.
                        </p>
                        <ol v-else class="divide-y rounded-lg border">
                            <li
                                v-for="run in detail.runs"
                                :key="run.id"
                                class="flex items-start gap-3 px-3 py-2"
                            >
                                <StatusDot
                                    :status="run.status"
                                    label
                                    class="mt-1.5"
                                />
                                <span class="min-w-0 flex-1 break-words">
                                    {{ run.message ?? 'OK' }}
                                </span>
                                <span
                                    class="shrink-0 text-right text-xs text-muted-foreground"
                                >
                                    <RelativeTime
                                        :datetime="run.checked_at"
                                    /><br />
                                    {{ run.duration_ms }} ms
                                </span>
                            </li>
                        </ol>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>

    <MonitorFormDialog
        v-if="detail && detail.checker"
        v-model:open="editing"
        :resource="{
            type: detail.monitor.resource.type,
            id: detail.monitor.resource.id,
        }"
        :checker-options="[detail.checker]"
        :monitor="detail.form"
        @saved="refresh"
    />

    <MuteDialog
        v-if="detail"
        v-model:open="muting"
        :url="MuteController.monitor(detail.monitor.id).url"
        :title="`Mute ${detail.monitor.name}`"
        description="It keeps running and incidents still open, but nothing is sent and the status ignores it."
        :muted-until="detail.monitor.muted_until"
        @update:open="(isOpen) => !isOpen && refresh()"
    />

    <ConfirmDialog
        v-if="detail"
        v-model:open="confirmingDelete"
        :title="`Delete the ${detail.monitor.name} monitor?`"
        @confirm="destroy"
    >
        It stops running. Its past runs stay until they're pruned.
    </ConfirmDialog>
</template>
