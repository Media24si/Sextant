<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
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
import RedetectSiteDialog from '@/components/app/RedetectSiteDialog.vue';
import RepoLink from '@/components/app/RepoLink.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import { Button } from '@/components/ui/button';

/*
 * Site page (SPEC §13.6). Latest results, monitors and incidents join in
 * Phases 3 and 4.
 */
const props = defineProps<{
    site: App.Data.SiteData;
    activity: App.Data.ActivityItemData[];
    front_services: App.Data.OptionData[];
    servers: App.Data.OptionData[];
    monitors: App.Data.Monitors.MonitorRowData[];
    checker_options: App.Data.Monitors.CheckerOptionData[];
    incidents: App.Data.Incidents.IncidentRowData[];
}>();

// Results arrive while the page is open (SPEC §13.7).
usePoll(10_000, { only: ['monitors', 'incidents', 'activity', 'site'] });

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
        RunChecksController.site(props.site.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (runningChecks.value = true),
            onFinish: () => (runningChecks.value = false),
        },
    );
}

const redetecting = ref(false);

const page = usePage();

const breadcrumbs = computed(() => [
    { title: 'Domains', href: DomainController.index() },
    {
        title: props.site.domain,
        href: DomainController.show(props.site.domain_id),
    },
    { title: props.site.hostname, href: SiteController.show(props.site.id) },
]);

const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy(): void {
    router.delete(SiteController.destroy(props.site.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}
</script>

<template>
    <Head :title="site.hostname" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <div
            v-if="site.deleted"
            role="status"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-card px-5 py-4"
        >
            <p>This site was deleted. Its history stays in the change feed.</p>
            <Button
                v-if="page.props.can.manage"
                variant="outline"
                @click="router.post(SiteController.restore(site.id))"
            >
                Restore site
            </Button>
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1
                        class="font-mono text-[32px] font-bold tracking-tight break-all"
                    >
                        {{ site.hostname }}
                    </h1>
                    <StatusBadge v-if="!site.deleted" :status="site.status" />
                </div>
                <p v-if="site.description" class="text-[15px]">
                    {{ site.description }}
                </p>
                <div
                    class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground"
                >
                    <a
                        :href="site.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-mono text-primary hover:underline"
                    >
                        {{ site.url
                        }}<span class="sr-only"> (opens in a new tab)</span>
                    </a>
                    <span>
                        Domain
                        <Link
                            :href="DomainController.show(site.domain_id)"
                            class="font-mono text-foreground hover:underline"
                            >{{ site.domain }}</Link
                        >
                    </span>
                    <span v-if="site.application_id">
                        Application
                        <Link
                            :href="
                                ApplicationController.show(site.application_id)
                            "
                            class="text-foreground hover:underline"
                            >{{ site.application }}</Link
                        >
                    </span>
                    <RepoLink v-if="site.github_url" :url="site.github_url" />
                </div>
            </div>

            <div
                v-if="page.props.can.manage && !site.deleted"
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
                <Button variant="outline" @click="redetecting = true"
                    >Re-run detection</Button
                >
                <Button as-child>
                    <Link :href="SiteController.edit(site.id)">Edit site</Link>
                </Button>
            </div>
        </header>

        <section class="rounded-xl border bg-card p-6" aria-labelledby="path">
            <h2 id="path" class="text-lg font-semibold">Serving path</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                What a request passes through before it reaches the server that
                builds the page. Dashed boxes are external services Sextant
                doesn't check directly.
            </p>
            <div class="mt-4 text-base">
                <ServingPath
                    :path="site.serving_path"
                    :hostname="site.hostname"
                    :backend-href="
                        site.server_id
                            ? ServerController.show(site.server_id)
                            : undefined
                    "
                />
            </div>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-[3fr_2fr]">
            <IncidentList :incidents="incidents" />

            <MonitorList
                :monitors="monitors"
                :checker-options="checker_options"
                :resource="{ type: 'site', id: site.id }"
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
            <section
                v-if="site.notes"
                class="rounded-xl border bg-card p-6 lg:col-span-2"
                aria-labelledby="notes"
            >
                <h2 id="notes" class="text-lg font-semibold">Notes</h2>
                <p class="mt-2 text-sm whitespace-pre-line">{{ site.notes }}</p>
            </section>
        </div>

        <RedetectSiteDialog
            v-if="page.props.can.manage && !site.deleted"
            v-model:open="redetecting"
            :site="site"
            :front-services="front_services"
            :servers="servers"
        />

        <ConfirmDialog
            v-model:open="confirmingDelete"
            :title="`Delete ${site.hostname}?`"
            :processing="deleting"
            @confirm="destroy"
        >
            The site leaves the lists; its history stays and an admin can
            restore it.
        </ConfirmDialog>
        <MuteDialog
            v-model:open="muting"
            :url="MuteController.site(site.id).url"
            :title="`Mute ${site.hostname}`"
            description="Its checks keep running and incidents still open, but nothing is sent and the status ignores them."
            :muted-until="mutedUntil"
        />
    </div>
</template>
