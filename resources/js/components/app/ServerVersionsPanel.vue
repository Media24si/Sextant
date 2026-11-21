<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import ServerVersionsController from '@/actions/App/Http/Controllers/ServerVersionsController';
import RelativeTime from '@/components/app/RelativeTime.vue';
import VersionCell from '@/components/app/VersionCell.vue';
import VersionsTestConnection from '@/components/app/VersionsTestConnection.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';

/*
 * Software versions on a server page (SPEC §13.6, prototype ServerDetail):
 * installed and package versions against the minimums, when they were read,
 * and Refresh. Until versions have been read it also shows the endpoint,
 * masked token, downloads and Test connection; after that those live only on
 * the edit page.
 */
const props = defineProps<{
    serverId: number;
    panel: App.Data.ServerVersionsPanelData;
}>();

const page = usePage();

function refresh(): void {
    router.post(
        ServerVersionsController.refresh(props.serverId).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                window.setTimeout(
                    () => router.reload({ only: ['versions', 'monitors'] }),
                    4000,
                ),
        },
    );
}
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border bg-card"
        aria-labelledby="versions"
    >
        <div
            class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4"
        >
            <div>
                <h2 id="versions" class="text-lg font-semibold">
                    Software versions
                </h2>
                <p v-if="panel.read_at" class="text-sm text-muted-foreground">
                    Collector ran
                    {{ formatDateTime(panel.read_at) }} (<RelativeTime
                        :datetime="panel.read_at"
                    />)<template v-if="panel.fetched_at">
                        · read {{ formatDateTime(panel.fetched_at) }}</template
                    >
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    Not read yet.
                </p>
            </div>
            <Button
                v-if="page.props.can.manage && panel.configured"
                size="sm"
                variant="outline"
                @click="refresh"
                >Refresh now</Button
            >
        </div>

        <p
            v-if="panel.last_message && panel.versions.length > 0"
            class="border-b bg-muted/30 px-5 py-2 text-sm text-muted-foreground"
        >
            Latest check: {{ panel.last_message }}
        </p>

        <table v-if="panel.versions.length > 0" class="w-full text-sm">
            <thead class="text-left text-muted-foreground">
                <tr class="border-b">
                    <th class="px-5 py-2 font-medium">Software</th>
                    <th class="px-5 py-2 font-medium">Installed</th>
                    <th class="px-5 py-2 font-medium">Minimum</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr
                    v-for="version in panel.versions"
                    :key="`${version.software}:${version.branch}`"
                    :class="{
                        'bg-status-warning-bg/40': version.below_minimum,
                    }"
                >
                    <td
                        class="px-5 py-2"
                        :class="{
                            'font-semibold text-status-warning-fg':
                                version.below_minimum,
                        }"
                    >
                        {{ version.label }}
                        <span
                            v-if="version.details?.cli !== undefined"
                            class="text-xs text-muted-foreground"
                        >
                            ({{
                                [
                                    version.details.cli ? 'CLI' : null,
                                    version.details.fpm ? 'FPM' : null,
                                ]
                                    .filter(Boolean)
                                    .join(', ')
                            }})
                        </span>
                    </td>
                    <td class="px-5 py-2">
                        <VersionCell
                            :version="
                                version.software === 'os'
                                    ? String(
                                          version.details?.pretty_name ??
                                              version.version,
                                      )
                                    : version.version
                            "
                            :package-version="version.package_version"
                            :below-minimum="version.below_minimum"
                        />
                        <span
                            v-if="version.package_version"
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ version.package_version }}</span
                        >
                    </td>
                    <td class="px-5 py-2">
                        <span
                            v-if="version.software === 'os'"
                            class="text-muted-foreground"
                        >
                            <template
                                v-if="
                                    panel.os_set &&
                                    panel.os_set !== panel.os_reported
                                "
                                >Set to {{ panel.os_set }}</template
                            >
                            <template v-else>—</template>
                        </span>
                        <span
                            v-else-if="version.min_version"
                            class="font-mono"
                            :class="
                                version.below_minimum
                                    ? 'text-status-warning-fg'
                                    : 'text-status-ok-fg'
                            "
                        >
                            {{ version.min_version }}
                            {{ version.below_minimum ? '· below' : '✓' }}
                        </span>
                        <span v-else class="text-muted-foreground"
                            >not set</span
                        >
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            v-if="panel.versions.length === 0"
            class="flex flex-col gap-3 border-t bg-muted/20 px-5 py-4 text-sm"
        >
            <template v-if="panel.configured">
                <p>
                    <span class="text-muted-foreground">Versions endpoint</span
                    ><br />
                    <span class="font-mono break-all">{{
                        panel.endpoint_url
                    }}</span>
                </p>
                <p>
                    <span class="text-muted-foreground">Bearer token</span>
                    <span class="ml-2 font-mono">{{ panel.token_masked }}</span>
                </p>
            </template>
            <p v-else class="text-muted-foreground">
                Not configured: Sextant reads versions from a small endpoint on
                the server. It can't on Windows.
            </p>

            <div
                v-if="page.props.can.manage"
                class="flex flex-wrap items-start gap-2"
            >
                <Button variant="outline" size="sm" as-child>
                    <Link :href="ServerController.edit(serverId)">{{
                        panel.configured
                            ? 'Set-up and token'
                            : 'Set up versions'
                    }}</Link>
                </Button>
                <template v-if="panel.configured">
                    <Button variant="outline" size="sm" as-child>
                        <a
                            :href="
                                ServerVersionsController.collector(serverId).url
                            "
                            >sextant-collect.sh</a
                        >
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a
                            :href="
                                ServerVersionsController.endpoint(serverId).url
                            "
                            >versions.php</a
                        >
                    </Button>
                </template>
            </div>
            <VersionsTestConnection
                v-if="page.props.can.manage && panel.configured"
                :server-id="serverId"
            />
        </div>
    </section>
</template>
