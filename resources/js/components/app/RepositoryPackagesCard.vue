<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import RepositoryController from '@/actions/App/Http/Controllers/RepositoryController';
import RelativeTime from '@/components/app/RelativeTime.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import VersionCell from '@/components/app/VersionCell.vue';
import { statusLabels } from '@/lib/status';

/*
 * The Packages card of an application or site page (SPEC §11.9, §13.6): the
 * repository its GitHub link points to, when it was last read, and every
 * locked package that has a minimum, below it first, including packages
 * pulled in by other packages (marked indirect). The full list is on the
 * repository page.
 */
const props = defineProps<{
    repository: App.Data.Repositories.RepositorySummaryData;
}>();

const belowCount = computed(
    () => props.repository.watched.filter((item) => item.below_minimum).length,
);
</script>

<template>
    <section class="rounded-xl border bg-card" aria-labelledby="packages">
        <div
            class="flex flex-wrap items-baseline justify-between gap-2 px-6 pt-5 pb-4"
        >
            <h2 id="packages" class="text-lg font-semibold">
                Packages
                <span class="font-normal text-muted-foreground">{{
                    repository.packages_count
                }}</span>
            </h2>
            <Link
                :href="RepositoryController.show(repository.id)"
                class="inline-flex items-center gap-2 font-mono text-sm hover:underline"
            >
                <StatusDot :status="repository.status" />
                {{ repository.full_name }}
                <span class="sr-only">{{
                    statusLabels[repository.status]
                }}</span>
            </Link>
        </div>
        <div class="border-t px-6 py-4 text-sm">
            <p class="text-muted-foreground">
                <template v-if="repository.via"
                    >Through the application {{ repository.via }}. </template
                ><template v-if="repository.read_at"
                    >From
                    {{
                        repository.lock_files.length
                            ? repository.lock_files.join(', ')
                            : 'no lock file'
                    }}, read
                    <RelativeTime :datetime="repository.read_at" />.</template
                ><template v-else>Not read yet.</template>
            </p>
            <p
                v-if="belowCount > 0"
                class="mt-2 font-semibold text-status-warning-fg"
            >
                {{ belowCount }} below the minimum
            </p>
            <table v-if="repository.watched.length > 0" class="mt-3 w-full">
                <thead class="text-left text-muted-foreground">
                    <tr>
                        <th class="py-1 pr-3 font-medium">Package</th>
                        <th class="py-1 pr-3 font-medium">Locked</th>
                        <th class="py-1 font-medium">Minimum</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in repository.watched"
                        :key="`${item.ecosystem}:${item.name}@${item.version}`"
                    >
                        <td class="py-1 pr-3">
                            <span class="font-mono">{{ item.name }}</span>
                            <span
                                v-if="!item.direct"
                                class="ml-1 text-muted-foreground"
                                title="Required by another package, not by the project itself"
                                >indirect</span
                            >
                        </td>
                        <td class="py-1 pr-3">
                            <VersionCell
                                :version="item.version"
                                :below-minimum="item.below_minimum"
                            />
                        </td>
                        <td class="py-1 font-mono text-muted-foreground">
                            {{ item.min_version }}
                            <a
                                v-if="item.below_minimum && item.cve_url"
                                :href="item.cve_url"
                                target="_blank"
                                rel="noopener"
                                class="ml-1 font-sans text-primary underline"
                                >CVE</a
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-else-if="repository.read_at"
                class="mt-2 text-muted-foreground"
            >
                None of its packages has a minimum.
            </p>
        </div>
    </section>
</template>
