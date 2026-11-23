<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import { Button } from '@/components/ui/button';

/*
 * "If web02 goes down": the sites, applications and domains that depend on a
 * server or an external service. An application or domain that is only
 * partly behind it says how much ("2 of 3 sites"); a whole one is red.
 */
const props = defineProps<{
    name: string;
    radius: App.Data.BlastRadiusData;
}>();

function count(total: number, word: string): string {
    return `${total} ${total === 1 ? word : `${word}s`}`;
}

const summary = computed(() =>
    [
        count(props.radius.sites, 'site'),
        count(props.radius.applications.length, 'application'),
        count(props.radius.domains.length, 'domain'),
    ].join(' · '),
);

const groups = computed(() => [
    {
        title: 'Applications',
        items: props.radius.applications,
        href: (id: number) => ApplicationController.show(id),
        empty: 'None of these sites belong to an application.',
    },
    {
        title: 'Domains',
        items: props.radius.domains,
        href: (id: number) => DomainController.show(id),
        empty: 'No domains.',
    },
]);

async function copyHostnames(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.radius.hostnames.join('\n'));
        toast.success(
            `Copied ${count(props.radius.hostnames.length, 'hostname')}, one per line.`,
        );
    } catch {
        // No clipboard on plain HTTP, or the browser refused.
        toast.error(
            "The browser didn't allow copying. The sites are listed below.",
        );
    }
}
</script>

<template>
    <section
        class="rounded-xl border bg-card p-6"
        aria-labelledby="blast-radius"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="blast-radius" class="text-lg font-semibold">
                    If {{ name }} goes down
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ summary }} depend on it. Red ones go down entirely.
                </p>
            </div>
            <Button
                v-if="radius.hostnames.length > 0"
                variant="outline"
                size="sm"
                @click="copyHostnames"
            >
                <Copy aria-hidden="true" /> Copy hostnames
            </Button>
        </div>

        <div class="mt-4 grid gap-x-8 gap-y-5 md:grid-cols-2">
            <div v-for="group in groups" :key="group.title">
                <h3 class="text-sm font-medium text-muted-foreground">
                    {{ group.title }}
                </h3>
                <ul v-if="group.items.length > 0" class="mt-1">
                    <li
                        v-for="item in group.items"
                        :key="item.id"
                        class="flex items-baseline justify-between gap-3 border-b py-2 text-sm last:border-0"
                    >
                        <Link
                            :href="group.href(item.id)"
                            class="font-medium hover:underline"
                            :class="{ 'font-mono': group.title === 'Domains' }"
                            >{{ item.name }}</Link
                        >
                        <span
                            v-if="item.affected >= item.total"
                            class="shrink-0 font-medium text-status-error-fg"
                            >all {{ count(item.total, 'site') }}</span
                        >
                        <span v-else class="shrink-0 text-muted-foreground"
                            >{{ item.affected }} of
                            {{ count(item.total, 'site') }}</span
                        >
                    </li>
                </ul>
                <p v-else class="mt-2 text-sm text-muted-foreground">
                    {{ group.empty }}
                </p>
            </div>
        </div>
    </section>
</template>
