<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import PageHeader from '@/components/app/PageHeader.vue';
import { Button } from '@/components/ui/button';

/* Overview (SPEC §13.6). Totals, issues and changes arrive in Phase 6. */
const page = usePage();
</script>

<template>
    <Head title="Overview" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-5 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Overview"
            description="Servers, domains and sites, and anything that needs attention."
        >
            <template v-if="page.props.can.manage" #actions>
                <Button variant="outline" as-child>
                    <Link :href="ServerController.create()">+ Add server</Link>
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="DomainController.create()">+ Add domain</Link>
                </Button>
                <Button as-child>
                    <Link :href="SiteController.create()">+ Add site</Link>
                </Button>
            </template>
        </PageHeader>

        <section
            class="rounded-xl border bg-card px-6 py-10 text-center text-muted-foreground"
        >
            Nothing to show yet. Totals, current issues and recent changes
            appear here once servers, domains and sites are added.
        </section>
    </div>
</template>
