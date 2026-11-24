<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import GithubAccessController from '@/actions/App/Http/Controllers/GithubAccessController';
import RepositoryController from '@/actions/App/Http/Controllers/RepositoryController';
import RunChecksController from '@/actions/App/Http/Controllers/RunChecksController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import Breadcrumbs from '@/components/app/Breadcrumbs.vue';
import IncidentList from '@/components/app/IncidentList.vue';
import MonitorList from '@/components/app/MonitorList.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import RepoLink from '@/components/app/RepoLink.vue';
import StatusBadge from '@/components/app/StatusBadge.vue';
import VersionCell from '@/components/app/VersionCell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/datetime';

/*
 * Repository page (SPEC §3.21, §13.6): who uses it, when its lock files were
 * last read, and every locked package with its minimum. Direct packages
 * show first; the filter reveals the rest.
 */
type Package = App.Data.Repositories.RepositoryPackageData;

const props = defineProps<{
    repository: App.Data.Repositories.RepositoryRowData;
    packages: Package[];
    monitors: App.Data.Monitors.MonitorRowData[];
    checker_options: App.Data.Monitors.CheckerOptionData[];
    incidents: App.Data.Incidents.IncidentRowData[];
}>();

// A read started with "Read now" shows up without reloading (SPEC §13.7).
usePoll(10_000, {
    only: ['repository', 'packages', 'monitors', 'incidents'],
});

const page = usePage();

const breadcrumbs = computed(() => [
    { title: 'Repositories', href: RepositoryController.index() },
    {
        title: props.repository.full_name,
        href: RepositoryController.show(props.repository.id),
    },
]);

const search = ref('');
const scope = ref<'direct' | 'all' | 'below'>('direct');

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.packages
        .filter((item) =>
            scope.value === 'below'
                ? item.below_minimum
                : scope.value === 'direct'
                  ? // Indirect packages that have a minimum are checked too, so they show here.
                    item.direct || item.min_version !== null
                  : true,
        )
        .filter((item) => term === '' || item.name.toLowerCase().includes(term))
        .sort(
            (a, b) =>
                Number(b.below_minimum) - Number(a.below_minimum) ||
                a.ecosystem.localeCompare(b.ecosystem) ||
                a.name.localeCompare(b.name),
        );
});

const reading = ref(false);

function readNow(): void {
    router.post(
        RunChecksController.repository(props.repository.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (reading.value = true),
            onFinish: () => (reading.value = false),
        },
    );
}

const selectClasses =
    'h-9 rounded-md border border-input bg-card px-3 text-sm shadow-xs dark:bg-input/30';
</script>

<template>
    <Head :title="repository.full_name" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />

        <div
            v-if="repository.deleted"
            role="status"
            class="rounded-xl border border-dashed bg-card px-5 py-4"
        >
            No site or application links this repository any more. Its last
            packages and history stay here.
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1
                        class="font-mono text-[32px] font-bold tracking-tight break-all"
                    >
                        {{ repository.full_name }}
                    </h1>
                    <StatusBadge
                        v-if="!repository.deleted"
                        :status="repository.status"
                    />
                </div>
                <div
                    class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground"
                >
                    <RepoLink :url="repository.url" />
                    <span v-if="repository.default_branch"
                        >Branch
                        <span class="font-mono text-foreground">{{
                            repository.default_branch
                        }}</span></span
                    >
                    <span v-if="repository.read_at"
                        >Read <RelativeTime :datetime="repository.read_at"
                    /></span>
                    <span v-else>Not read yet</span>
                    <span v-if="repository.token_expires_at"
                        >Token expires
                        {{ formatDateTime(repository.token_expires_at) }}</span
                    >
                </div>
                <p
                    v-if="!repository.has_token"
                    class="text-sm text-status-warning-fg"
                >
                    Sextant has no GitHub token for {{ repository.owner }}, so
                    it can't read this repository.
                    <Link
                        v-if="page.props.can.manage"
                        :href="GithubAccessController.index()"
                        class="underline"
                        >Add a read-only token</Link
                    >
                </p>
            </div>

            <Button
                v-if="page.props.can.manage && !repository.deleted"
                variant="outline"
                :disabled="reading || monitors.length === 0"
                @click="readNow"
                >Read now</Button
            >
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-[3fr_2fr]">
            <section
                class="min-w-0 rounded-xl border bg-card"
                aria-labelledby="packages"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-4"
                >
                    <h2 id="packages" class="text-lg font-semibold">
                        Packages
                        <span class="font-normal text-muted-foreground">{{
                            packages.length
                        }}</span>
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <Input
                            v-model="search"
                            class="h-9 w-48"
                            placeholder="Find a package"
                            aria-label="Find a package"
                        />
                        <select
                            v-model="scope"
                            :class="selectClasses"
                            aria-label="Which packages"
                        >
                            <option value="direct">
                                Direct and with a minimum
                            </option>
                            <option value="all">All</option>
                            <option value="below">Below minimum</option>
                        </select>
                    </div>
                </div>
                <p
                    v-if="repository.read_at"
                    class="border-t px-6 py-3 text-sm text-muted-foreground"
                >
                    From
                    {{ repository.lock_files.join(', ') || 'no lock file' }} in
                    the root of {{ repository.default_branch }}.
                    <template v-if="repository.below_count > 0">
                        <span class="font-semibold text-status-warning-fg"
                            >{{ repository.below_count }} below the
                            minimum.</span
                        >
                    </template>
                </p>
                <div class="overflow-x-auto border-t">
                    <table v-if="shown.length > 0" class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-6 py-2 font-medium">Package</th>
                                <th class="px-3 py-2 font-medium">Locked</th>
                                <th class="px-3 py-2 font-medium">Minimum</th>
                                <th class="px-6 py-2 font-medium">
                                    <span class="sr-only">Kind</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="item in shown"
                                :key="`${item.ecosystem}:${item.name}@${item.version}`"
                            >
                                <td class="px-6 py-2 font-mono break-all">
                                    {{ item.name }}
                                </td>
                                <td class="px-3 py-2">
                                    <VersionCell
                                        :version="item.version"
                                        :below-minimum="item.below_minimum"
                                    />
                                </td>
                                <td
                                    class="px-3 py-2 font-mono text-muted-foreground"
                                >
                                    {{ item.min_version ?? '—' }}
                                    <a
                                        v-if="
                                            item.below_minimum && item.cve_url
                                        "
                                        :href="item.cve_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="ml-1 font-sans text-primary underline"
                                        >CVE</a
                                    >
                                </td>
                                <td
                                    class="px-6 py-2 text-right whitespace-nowrap text-muted-foreground"
                                >
                                    {{
                                        item.ecosystem === 'composer'
                                            ? 'Composer'
                                            : 'npm'
                                    }}<template v-if="item.dev"> · dev</template
                                    ><template v-if="!item.direct">
                                        · indirect</template
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-6 py-6 text-sm text-muted-foreground">
                        {{
                            packages.length === 0
                                ? repository.read_at
                                    ? 'No packages: there is no lock file in the root of the default branch.'
                                    : 'Not read yet.'
                                : 'No package matches.'
                        }}
                    </p>
                </div>
            </section>

            <div class="flex min-w-0 flex-col gap-6">
                <section
                    class="rounded-xl border bg-card"
                    aria-labelledby="used-by"
                >
                    <h2
                        id="used-by"
                        class="px-6 pt-5 pb-4 text-lg font-semibold"
                    >
                        Used by
                    </h2>
                    <ul class="divide-y border-t text-sm">
                        <li
                            v-for="user in repository.used_by"
                            :key="`${user.type}:${user.id}`"
                            class="px-6 py-2"
                        >
                            <Link
                                :href="
                                    user.type === 'application'
                                        ? ApplicationController.show(user.id)
                                        : SiteController.show(user.id)
                                "
                                :class="[
                                    'hover:underline',
                                    user.type === 'site' && 'font-mono',
                                ]"
                                >{{ user.name }}</Link
                            >
                            <span class="text-muted-foreground">
                                ·
                                {{
                                    user.type === 'application'
                                        ? 'application'
                                        : user.via
                                          ? `site, through ${user.via}`
                                          : 'site'
                                }}</span
                            >
                        </li>
                        <li
                            v-if="repository.used_by.length === 0"
                            class="px-6 py-2 text-muted-foreground"
                        >
                            Nothing links it any more.
                        </li>
                    </ul>
                </section>
                <MonitorList
                    :monitors="monitors"
                    :checker-options="checker_options"
                    :resource="{ type: 'repository', id: repository.id }"
                />
                <IncidentList :incidents="incidents" />
            </div>
        </div>
    </div>
</template>
