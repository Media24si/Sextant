<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import MonitorBadges from '@/components/app/MonitorBadges.vue';
import MonitorDrawer from '@/components/app/MonitorDrawer.vue';
import MonitorFormDialog from '@/components/app/MonitorFormDialog.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { everyLabel, monitorDotStatus } from '@/lib/monitors';

/*
 * The Monitors card of a server, domain or site page (SPEC §13.6, prototype
 * ServerDetail): one row per monitor with its latest result; a row opens the
 * drawer.
 */
defineProps<{
    monitors: App.Data.Monitors.MonitorRowData[];
    checkerOptions: App.Data.Monitors.CheckerOptionData[];
    resource: { type: string; id: number };
}>();

const page = usePage();
const adding = ref(false);
const drawerOpen = ref(false);
const selected = ref<number | null>(null);

function openDrawer(id: number): void {
    selected.value = id;
    drawerOpen.value = true;
}
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border bg-card"
        aria-labelledby="monitors"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
        >
            <h2 id="monitors" class="text-lg font-semibold">Monitors</h2>
            <div class="flex items-center gap-3">
                <Link
                    :href="MonitorController.index()"
                    class="text-sm text-muted-foreground hover:underline"
                    >All monitors</Link
                >
                <Button
                    v-if="page.props.can.manage && checkerOptions.length > 0"
                    size="sm"
                    variant="outline"
                    @click="adding = true"
                    >+ Add monitor</Button
                >
            </div>
        </div>

        <p
            v-if="monitors.length === 0"
            class="px-5 py-6 text-sm text-muted-foreground"
        >
            No monitors yet, so the status stays Unknown.
        </p>

        <ul v-else class="divide-y text-sm">
            <li v-for="monitor in monitors" :key="monitor.id">
                <button
                    type="button"
                    class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 text-left hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                    :class="{ 'opacity-60': !monitor.enabled }"
                    @click="openDrawer(monitor.id)"
                >
                    <StatusDot :status="monitorDotStatus(monitor)" label />
                    <span
                        class="flex min-w-48 flex-1 flex-wrap items-center gap-2 font-medium"
                    >
                        {{ monitor.name }}
                        <MonitorBadges :monitor="monitor" />
                    </span>
                    <span
                        class="text-muted-foreground"
                        :class="{ 'text-status-warning-fg': monitor.pending }"
                    >
                        {{ monitor.message ?? 'Not checked yet' }} ·
                        {{ everyLabel(monitor.interval) }}
                    </span>
                </button>
            </li>
        </ul>

        <MonitorDrawer v-model:open="drawerOpen" :monitor-id="selected" />
        <MonitorFormDialog
            v-model:open="adding"
            :resource="resource"
            :checker-options="checkerOptions"
        />
    </section>
</template>
