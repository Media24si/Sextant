<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Activity,
    Gauge,
    Globe,
    LayoutGrid,
    List,
    Server,
    ShieldCheck,
    SquareDashed,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import ChoiceController from '@/actions/App/Http/Controllers/ChoiceController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import UserController from '@/actions/App/Http/Controllers/UserController';
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
    {
        title: 'Domains',
        href: DomainController.index(),
        icon: Globe,
        count: page.props.nav?.domains,
    },
    { title: 'Monitors', href: MonitorController.index(), icon: Gauge },
    { title: 'Incidents', icon: TriangleAlert },
    { title: 'Activity', href: ActivityController.index(), icon: Activity },
]);

const adminNavItems: SidebarNavItem[] = [
    { title: 'Minimum versions', icon: ShieldCheck },
    {
        title: 'External services',
        href: ExternalServiceController.index(),
        icon: SquareDashed,
    },
    { title: 'Lists', href: ChoiceController.index(), icon: List },
    { title: 'Users', href: UserController.index(), icon: Users },
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
