<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import SiteImportController from '@/actions/App/Http/Controllers/SiteImportController';
import ActivityList from '@/components/app/ActivityList.vue';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import EmptyState from '@/components/app/EmptyState.vue';
import RepoLink from '@/components/app/RepoLink.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { statusLabels } from '@/lib/status';

/*
 * Application page (SPEC §3.19, §13.6): its hostnames, each a site with its
 * own domain, serving path and checks.
 */
const props = defineProps<{
    application: App.Data.ApplicationRowData;
    notes: string | null;
    sites: App.Data.SiteRowData[];
    activity: App.Data.ActivityItemData[];
}>();

const page = usePage();

const breadcrumbs = computed(() => [
    { title: 'Applications', href: ApplicationController.index() },
    {
        title: props.application.name,
        href: ApplicationController.show(props.application.id),
    },
]);

const confirmingDelete = ref(false);
const deleting = ref(false);

function destroy(): void {
    router.delete(ApplicationController.destroy(props.application.id), {
        onStart: () => (deleting.value = true),
        onFinish: () => {
            deleting.value = false;
            confirmingDelete.value = false;
        },
    });
}
</script>

<template>
    <Head :title="application.name" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <div
            v-if="application.deleted"
            role="status"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-card px-5 py-4"
        >
            <p>
                This application was deleted. Its history stays in the change
                feed.
            </p>
            <Button
                v-if="page.props.can.manage"
                variant="outline"
                @click="
                    router.post(ApplicationController.restore(application.id))
                "
            >
                Restore application
            </Button>
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-[32px] font-bold tracking-tight">
                        {{ application.name }}
                    </h1>
                    <StatusBadge
                        v-if="!application.deleted && sites.length > 0"
                        :status="application.status"
                    />
                </div>
                <p v-if="application.description" class="text-[15px]">
                    {{ application.description }}
                </p>
                <RepoLink
                    v-if="application.github_url"
                    :url="application.github_url"
                />
                <p v-if="notes" class="max-w-3xl text-sm whitespace-pre-line">
                    {{ notes }}
                </p>
            </div>

            <div
                v-if="page.props.can.manage && !application.deleted"
                class="flex flex-wrap gap-2"
            >
                <Button variant="outline" @click="confirmingDelete = true"
                    >Delete</Button
                >
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            SiteImportController.create({
                                query: { application: application.id },
                            })
                        "
                        >+ Add hostnames</Link
                    >
                </Button>
                <Button as-child>
                    <Link :href="ApplicationController.edit(application.id)"
                        >Edit application</Link
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
                    Hostnames
                    <span class="font-normal text-muted-foreground">{{
                        sites.length
                    }}</span>
                </h2>
                <ul v-if="sites.length > 0" class="divide-y border-t">
                    <li
                        v-for="site in sites"
                        :key="site.id"
                        class="grid items-center gap-3 px-6 py-3 text-sm md:grid-cols-[2fr_2fr_1fr]"
                    >
                        <div class="min-w-0">
                            <Link
                                :href="SiteController.show(site.id)"
                                class="font-mono hover:underline"
                                >{{ site.hostname }}</Link
                            >
                            <p class="text-muted-foreground">
                                Domain
                                <Link
                                    :href="
                                        DomainController.show(site.domain_id)
                                    "
                                    class="font-mono hover:underline"
                                    >{{ site.domain }}</Link
                                >
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
                <EmptyState
                    v-else
                    title="No hostnames yet"
                    description="Paste the nginx server_name line on the Import page, or pick this application on a site's form."
                />
            </section>

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
            :title="`Delete ${application.name}?`"
            :processing="deleting"
            @confirm="destroy"
        >
            The application leaves the lists; its history stays and an admin can
            restore it. An application that still has sites can't be deleted.
        </ConfirmDialog>
    </div>
</template>
