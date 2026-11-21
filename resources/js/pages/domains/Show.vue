<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import RunChecksController from '@/actions/App/Http/Controllers/RunChecksController';
import ActivityList from '@/components/app/ActivityList.vue';
import IncidentList from '@/components/app/IncidentList.vue';
import MonitorList from '@/components/app/MonitorList.vue';
import MuteDialog from '@/components/app/MuteDialog.vue';
import MuteController from '@/actions/App/Http/Controllers/MuteController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import ExpiryDate from '@/components/app/ExpiryDate.vue';
import RepoLink from '@/components/app/RepoLink.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { statusLabels } from '@/lib/status';

/*
 * Domain page (SPEC §13.6). Registry data comes from the expiry check
 * (Phase 3); monitors and incidents join in Phases 3 and 4.
 */
const props = defineProps<{
    domain: App.Data.DomainRowData;
    notes: string | null;
    activity: App.Data.ActivityItemData[];
    monitors: App.Data.Monitors.MonitorRowData[];
    checker_options: App.Data.Monitors.CheckerOptionData[];
    incidents: App.Data.Incidents.IncidentRowData[];
}>();

// Results arrive while the page is open (SPEC §13.7).
usePoll(10_000, { only: ['monitors', 'incidents', 'activity', 'domain'] });

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
        RunChecksController.domain(props.domain.id).url,
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
    { title: 'Domains', href: DomainController.index() },
    { title: props.domain.name, href: DomainController.show(props.domain.id) },
]);

const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy(): void {
    router.delete(DomainController.destroy(props.domain.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}
</script>

<template>
    <Head :title="domain.name" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <div
            v-if="domain.deleted"
            role="status"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-card px-5 py-4"
        >
            <p>
                This domain was deleted. Its history stays in the change feed.
            </p>
            <Button
                v-if="page.props.can.manage"
                variant="outline"
                @click="router.post(DomainController.restore(domain.id))"
            >
                Restore domain
            </Button>
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="font-mono text-[32px] font-bold tracking-tight">
                        {{ domain.name }}
                    </h1>
                    <StatusBadge
                        v-if="!domain.deleted"
                        :status="domain.status"
                    />
                </div>
                <dl class="flex flex-wrap gap-x-6 gap-y-1 text-[15px]">
                    <div class="flex gap-1.5">
                        <dt class="text-muted-foreground">Expires</dt>
                        <dd>
                            <ExpiryDate
                                :date="domain.expires_at"
                                :days="domain.expires_in_days"
                            />
                        </dd>
                    </div>
                    <div class="flex gap-1.5">
                        <dt class="text-muted-foreground">Registrar</dt>
                        <dd>{{ domain.registrar ?? '—' }}</dd>
                    </div>
                </dl>
                <p v-if="notes" class="max-w-3xl text-sm whitespace-pre-line">
                    {{ notes }}
                </p>
            </div>

            <div
                v-if="page.props.can.manage && !domain.deleted"
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
                <Button variant="outline" @click="confirmingDelete = true"
                    >Delete</Button
                >
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            SiteController.create({
                                query: { domain: domain.id },
                            })
                        "
                        >+ Add site</Link
                    >
                </Button>
                <Button as-child>
                    <Link :href="DomainController.edit(domain.id)"
                        >Edit domain</Link
                    >
                </Button>
            </div>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-[3fr_2fr]">
            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-labelledby="sites"
            >
                <h2 id="sites" class="px-6 pt-5 pb-4 text-lg font-semibold">
                    Sites
                    <span class="font-normal text-muted-foreground">{{
                        domain.sites.length
                    }}</span>
                </h2>
                <ul v-if="domain.sites.length > 0" class="divide-y border-t">
                    <li
                        v-for="site in domain.sites"
                        :key="site.id"
                        class="grid items-center gap-3 px-6 py-3 text-sm md:grid-cols-[2fr_2fr_1fr]"
                    >
                        <div>
                            <span class="inline-flex items-center gap-2">
                                <span class="font-mono">{{
                                    site.hostname
                                }}</span>
                                <RepoLink
                                    v-if="site.github_url"
                                    :url="site.github_url"
                                />
                            </span>
                            <p
                                v-if="site.description"
                                class="text-muted-foreground"
                            >
                                {{ site.description }}
                            </p>
                        </div>
                        <ServingPath
                            :path="site.serving_path"
                            :backend-href="
                                site.server_id
                                    ? ServerController.show(site.server_id)
                                    : undefined
                            "
                        />
                        <span class="inline-flex items-center gap-2">
                            <StatusDot :status="site.status" />
                            {{ statusLabels[site.status] }}
                        </span>
                    </li>
                </ul>
                <EmptyState v-else title="No sites on this domain yet" />
            </section>

            <IncidentList :incidents="incidents" />

            <MonitorList
                :monitors="monitors"
                :checker-options="checker_options"
                :resource="{ type: 'domain', id: domain.id }"
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
            :title="`Delete ${domain.name}?`"
            :processing="deleting"
            @confirm="destroy"
        >
            The domain leaves the lists; its history stays and an admin can
            restore it. A domain that still has sites can't be deleted.
        </ConfirmDialog>
        <MuteDialog
            v-model:open="muting"
            :url="MuteController.domain(domain.id).url"
            :title="`Mute ${domain.name}`"
            description="Its checks keep running and incidents still open, but nothing is sent and the status ignores them."
            :muted-until="mutedUntil"
        />
    </div>
</template>
