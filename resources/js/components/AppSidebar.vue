<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Activity,
    Globe,
    LayoutGrid,
    List,
    Server,
    ShieldCheck,
    SquareDashed,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { SidebarNavItem } from '@/types';

const page = usePage();

/*
 * SPEC §13.4. Items without an href are pages from later phases; they show
 * disabled until their route exists.
 */
const mainNavItems = computed<SidebarNavItem[]>(() => [
    { title: 'Overview', href: dashboard(), icon: LayoutGrid },
    {
        title: 'Servers',
        href: ServerController.index(),
        icon: Server,
        count: page.props.nav?.servers,
    },
    { title: 'Domains', icon: Globe },
    { title: 'Incidents', icon: TriangleAlert },
    { title: 'Activity', icon: Activity },
]);

const adminNavItems: SidebarNavItem[] = [
    { title: 'Minimum versions', icon: ShieldCheck },
    {
        title: 'External services',
        href: ExternalServiceController.index(),
        icon: SquareDashed,
    },
    { title: 'Lists', icon: List },
    { title: 'Users', icon: Users },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader class="px-3 pt-5 pb-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="hover:bg-transparent active:bg-transparent"
                    >
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-4">
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="page.props.can.manage"
                label="Admin"
                :items="adminNavItems"
            />
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
