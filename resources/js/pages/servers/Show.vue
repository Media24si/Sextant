<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import RunChecksController from '@/actions/App/Http/Controllers/RunChecksController';
import ActivityList from '@/components/app/ActivityList.vue';
import IncidentList from '@/components/app/IncidentList.vue';
import MonitorList from '@/components/app/MonitorList.vue';
import ServerVersionsPanel from '@/components/app/ServerVersionsPanel.vue';
import MuteDialog from '@/components/app/MuteDialog.vue';
import MuteController from '@/actions/App/Http/Controllers/MuteController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { statusLabels } from '@/lib/status';

const props = defineProps<{
    server: App.Data.ServerData;
    traffic: App.Data.TrafficGroupData[];
    sites: App.Data.ServedSiteData[];
    sites_total: number;
    activity: App.Data.ActivityItemData[];
    monitors: App.Data.Monitors.MonitorRowData[];
    checker_options: App.Data.Monitors.CheckerOptionData[];
    incidents: App.Data.Incidents.IncidentRowData[];
    versions: App.Data.ServerVersionsPanelData;
}>();

// Results arrive while the page is open (SPEC §13.7).
usePoll(10_000, {
    only: ['monitors', 'incidents', 'activity', 'server', 'versions'],
});

const muting = ref(false);

/** The earliest mute among this page's monitors, when every one is muted. */
const mutedUntil = computed(() => {
    const mutes = props.monitors.map((monitor) => monitor.muted_until);

    return mutes.length > 0 && mutes.every(Boolean)
        ? (mutes.sort()[0] ?? null)
        : null;
});

const runningChecks = ref(false);

function runChecks(): void {
    router.post(
        RunChecksController.server(props.server.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (runningChecks.value = true),
            onFinish: () => (runningChecks.value = false),
        },
    );
}

const page = usePage();

const breadcrumbs = computed(() => [
    { title: 'Servers', href: ServerController.index() },
    { title: props.server.name, href: ServerController.show(props.server.id) },
]);

const kindLabels: Record<App.Enums.ExternalServiceKind, string> = {
    cdn: 'External CDN',
    proxy: 'External proxy',
    other: 'External service',
};

const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy(): void {
    router.delete(ServerController.destroy(props.server.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}

function restore(): void {
    router.post(ServerController.restore(props.server.id));
}
</script>

<template>
    <Head :title="server.name" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <div
            v-if="server.deleted"
            role="status"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-card px-5 py-4"
        >
            <p>
                This server was deleted. Its history stays in the change feed.
            </p>
            <Button
                v-if="page.props.can.manage"
                variant="outline"
                @click="restore"
            >
                Restore server
            </Button>
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="font-mono text-[32px] font-bold tracking-tight">
                        {{ server.name }}
                    </h1>
                    <StatusBadge
                        v-if="!server.deleted"
                        :status="server.status"
                    />
                </div>
                <p
                    class="flex flex-wrap gap-x-5 gap-y-1 text-[15px] text-muted-foreground"
                >
                    <span v-if="server.hostname" class="font-mono">{{
                        server.hostname
                    }}</span>
                    <span
                        v-for="address in server.addresses"
                        :key="address.id"
                        class="font-mono text-foreground"
                        >{{ address.ip
                        }}<span
                            v-if="address.is_primary"
                            class="font-sans text-muted-foreground"
                        >
                            primary</span
                        ></span
                    >
                    <span v-if="server.provider"
                        >Provider: {{ server.provider }}</span
                    >
                    <span v-if="server.os">{{ server.os }}</span>
                </p>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Backups stored on</span>
                    <template v-if="server.backup_locations.length > 0">
                        <span
                            v-for="location in server.backup_locations"
                            :key="location"
                            class="rounded-full border bg-card px-3 py-0.5"
                            >{{ location }}</span
                        >
                    </template>
                    <span
                        v-else
                        class="font-semibold text-orange-700 dark:text-orange-400"
                        >None set</span
                    >
                </div>
                <p
                    v-if="server.notes"
                    class="max-w-3xl text-sm whitespace-pre-line"
                >
                    {{ server.notes }}
                </p>
            </div>

            <div
                v-if="page.props.can.manage && !server.deleted"
                class="flex flex-wrap gap-2"
            >
                <Button
                    variant="outline"
                    :disabled="runningChecks || monitors.length === 0"
                    @click="runChecks"
                    >Run checks now</Button
                >
                <Button
                    variant="outline"
                    :disabled="monitors.length === 0"
                    @click="muting = true"
                    >{{ mutedUntil ? 'Muted' : 'Mute until…' }}</Button
                >
                <Button variant="outline" @click="confirmingDelete = true">
                    Delete
                </Button>
                <Button as-child>
                    <Link :href="ServerController.edit(server.id)"
                        >Edit server</Link
                    >
                </Button>
            </div>
        </header>

        <section
            class="rounded-xl border bg-card p-6"
            aria-labelledby="traffic"
        >
            <h2 id="traffic" class="text-lg font-semibold">
                How traffic reaches {{ server.name }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ sites_total }}
                {{ sites_total === 1 ? 'site uses' : 'sites use' }}
                {{ server.name }} as their backend. Dashed boxes are external
                services Sextant doesn't check directly.
            </p>

            <div
                v-if="traffic.length > 0"
                class="mt-5 grid items-center gap-5 lg:grid-cols-[1fr_auto_1fr]"
            >
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="group in traffic"
                        :key="group.name"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-lg px-5 py-3"
                        :class="
                            group.kind
                                ? 'border-[1.5px] border-dashed border-status-unknown'
                                : 'border bg-muted'
                        "
                    >
                        <div>
                            <p class="font-semibold">{{ group.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                <template v-if="group.kind">{{
                                    kindLabels[group.kind]
                                }}</template>
                                <template v-else
                                    >DNS points straight at
                                    {{ server.name }}</template
                                >
                                · {{ group.sites_count }}
                                {{ group.sites_count === 1 ? 'site' : 'sites' }}
                            </p>
                        </div>
                        <span class="font-mono text-sm text-muted-foreground"
                            >{{ group.first_site
                            }}<template v-if="group.sites_count > 1">
                                +{{ group.sites_count - 1 }}</template
                            ></span
                        >
                    </li>
                </ul>
                <ArrowRight
                    class="hidden size-8 text-muted-foreground lg:block"
                    aria-hidden="true"
                />
                <div
                    class="rounded-xl border-2 border-primary bg-blue-50 px-6 py-5 text-blue-900 dark:bg-blue-950 dark:text-blue-100"
                >
                    <p class="font-mono text-lg font-semibold">
                        {{ server.name }}
                    </p>
                    <p class="mt-1">
                        {{ sites_total }}
                        {{ sites_total === 1 ? 'site' : 'sites' }} built here
                    </p>
                </div>
            </div>
            <EmptyState
                v-else
                :title="`No sites use ${server.name} as their backend yet`"
                description="Choose this server as the backend when adding or editing a site."
            />
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-[3fr_2fr]">
            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-labelledby="sites-served"
            >
                <div class="flex items-baseline justify-between px-6 pt-5 pb-4">
                    <h2 id="sites-served" class="text-lg font-semibold">
                        Sites served
                    </h2>
                    <span
                        v-if="sites_total > 0"
                        class="text-sm text-muted-foreground"
                        >{{ sites.length }} of {{ sites_total }}</span
                    >
                </div>
                <div v-if="sites.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted text-left text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-6 py-3 font-medium">
                                    Site
                                </th>
                                <th scope="col" class="px-3 py-3 font-medium">
                                    Serving path
                                </th>
                                <th scope="col" class="px-6 py-3 font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="site in sites"
                                :key="site.id"
                                class="border-t"
                            >
                                <td class="px-6 py-3">
                                    <span class="font-mono">{{
                                        site.hostname
                                    }}</span>
                                    <span
                                        v-if="site.description"
                                        class="block text-xs text-muted-foreground"
                                        >{{ site.description }}</span
                                    >
                                </td>
                                <td class="px-3 py-3">
                                    <ServingPath :path="site.serving_path" />
                                </td>
                                <td class="px-6 py-3">
                                    <span
                                        class="inline-flex items-center gap-2"
                                    >
                                        <StatusDot :status="site.status" />
                                        {{ statusLabels[site.status] }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="No sites yet" />
            </section>

            <ServerVersionsPanel :server-id="server.id" :panel="versions" />

            <IncidentList
                :incidents="incidents"
                description="Including the sites server serves as their backend."
                :all-query="{ 'filter[server]': String(server.id) }"
            />

            <MonitorList
                :monitors="monitors"
                :checker-options="checker_options"
                :resource="{ type: 'server', id: server.id }"
            />

            <section
                class="rounded-xl border bg-card"
                aria-labelledby="changes"
            >
                <h2 id="changes" class="px-6 pt-5 pb-4 text-lg font-semibold">
                    Changes
                </h2>
                <div class="border-t px-6">
                    <ActivityList :items="activity" />
                </div>
            </section>
        </div>

        <ConfirmDialog
            v-model:open="confirmingDelete"
            :title="`Delete ${server.name}?`"
            :processing="deleting"
            @confirm="destroy"
        >
            The server and its addresses leave the lists. Its history stays, and
            an admin can restore it later. A server that is still the backend of
            sites can't be deleted.
        </ConfirmDialog>
        <MuteDialog
            v-model:open="muting"
            :url="MuteController.server(server.id).url"
            :title="`Mute ${server.name}`"
            description="Its checks keep running and incidents still open, but nothing is sent and the status ignores them. Every site whose backend is this server is muted too."
            :muted-until="mutedUntil"
        />
    </div>
</template>
