<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import GithubAccessController from '@/actions/App/Http/Controllers/GithubAccessController';
import RepositoryController from '@/actions/App/Http/Controllers/RepositoryController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import EmptyState from '@/components/app/EmptyState.vue';
import PageHeader from '@/components/app/PageHeader.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import { statusLabels } from '@/lib/status';

/*
 * Repositories (SPEC §3.21, §13.6): one per GitHub link of a site or
 * application. Sextant reads their lock files daily with the owner's
 * read-only token and compares the packages with the minimums.
 */
defineProps<{
    repositories: App.Data.Repositories.RepositoryRowData[];
}>();

const page = usePage();
</script>

<template>
    <Head title="Repositories" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Repositories"
            description="The code behind sites and applications, from their GitHub links. Packages in composer.lock, package-lock.json and pnpm-lock.yaml are read daily and compared with the minimum versions."
        />

        <section class="overflow-x-auto rounded-xl border bg-card">
            <EmptyState
                v-if="repositories.length === 0"
                title="No repositories yet"
                description="Add a GitHub link to an application or site and its repository shows here."
            />
            <table v-else class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-5 py-3 font-medium">Repository</th>
                        <th class="px-5 py-3 font-medium">Used by</th>
                        <th class="px-5 py-3 font-medium">Packages</th>
                        <th class="px-5 py-3 font-medium">Read</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="repository in repositories"
                        :key="repository.id"
                        class="align-top"
                    >
                        <td class="px-5 py-3">
                            <Link
                                :href="RepositoryController.show(repository.id)"
                                class="inline-flex items-center gap-2 font-mono font-medium hover:underline"
                            >
                                <StatusDot :status="repository.status" />
                                {{ repository.full_name }}
                                <span class="sr-only">{{
                                    statusLabels[repository.status]
                                }}</span>
                            </Link>
                            <p
                                v-if="!repository.has_token"
                                class="text-status-warning-fg"
                            >
                                No GitHub token for {{ repository.owner }}
                                <Link
                                    v-if="page.props.can.manage"
                                    :href="GithubAccessController.index()"
                                    class="underline"
                                    >Add one</Link
                                >
                            </p>
                        </td>
                        <td class="px-5 py-3">
                            <ul class="flex flex-col gap-0.5">
                                <li
                                    v-for="user in repository.used_by"
                                    :key="`${user.type}:${user.id}`"
                                >
                                    <Link
                                        :href="
                                            user.type === 'application'
                                                ? ApplicationController.show(
                                                      user.id,
                                                  )
                                                : SiteController.show(user.id)
                                        "
                                        :class="[
                                            'hover:underline',
                                            user.type === 'site' && 'font-mono',
                                        ]"
                                        >{{ user.name }}</Link
                                    >
                                </li>
                            </ul>
                        </td>
                        <td class="px-5 py-3">
                            <template v-if="repository.read_at">
                                {{ repository.packages_count }}
                                <span class="text-muted-foreground"
                                    >from
                                    {{
                                        repository.lock_files.join(', ') ||
                                        'no lock file'
                                    }}</span
                                >
                                <p
                                    v-if="repository.below_count > 0"
                                    class="font-semibold text-status-warning-fg"
                                >
                                    {{ repository.below_count }} below the
                                    minimum
                                </p>
                            </template>
                            <span v-else class="text-muted-foreground"
                                >Not read yet</span
                            >
                        </td>
                        <td class="px-5 py-3 text-muted-foreground">
                            <RelativeTime
                                v-if="repository.read_at"
                                :datetime="repository.read_at"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
