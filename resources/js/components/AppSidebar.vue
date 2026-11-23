<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Activity,
    AppWindow,
    Boxes,
    Gauge,
    Globe,
    KeyRound,
    LayoutGrid,
    List,
    Server,
    ShieldCheck,
    SquareDashed,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
import ApplicationController from '@/actions/App/Http/Controllers/ApplicationController';
import ChoiceController from '@/actions/App/Http/Controllers/ChoiceController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import ExternalServiceController from '@/actions/App/Http/Controllers/ExternalServiceController';
import GithubAccessController from '@/actions/App/Http/Controllers/GithubAccessController';
import IncidentController from '@/actions/App/Http/Controllers/IncidentController';
import MinimumVersionController from '@/actions/App/Http/Controllers/MinimumVersionController';
import MonitorController from '@/actions/App/Http/Controllers/MonitorController';
import UserController from '@/actions/App/Http/Controllers/UserController';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
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
 * SPEC §13.4, grouped: the inventory first (what we have and how it
 * connects), then monitoring, then settings. Readers see every page they can
 * open; only Users and GitHub access are for admins.
 */
const overviewItems: SidebarNavItem[] = [
    { title: 'Overview', href: dashboard(), icon: LayoutGrid },
];

const inventoryItems = computed<SidebarNavItem[]>(() => [
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
    {
        title: 'Sites',
        href: SiteController.index(),
        icon: AppWindow,
        count: page.props.nav?.sites,
    },
    {
        title: 'Applications',
        href: ApplicationController.index(),
        icon: Boxes,
    },
    {
        title: 'External services',
        href: ExternalServiceController.index(),
        icon: SquareDashed,
    },
]);

const monitoringItems = computed<SidebarNavItem[]>(() => [
    { title: 'Monitors', href: MonitorController.index(), icon: Gauge },
    {
        title: 'Incidents',
        href: IncidentController.index(),
        icon: TriangleAlert,
        count: page.props.nav?.incidents,
    },
    { title: 'Activity', href: ActivityController.index(), icon: Activity },
    {
        title: 'Minimum versions',
        href: MinimumVersionController.index(),
        icon: ShieldCheck,
    },
]);

const settingsItems = computed<SidebarNavItem[]>(() => [
    { title: 'Lists', href: ChoiceController.index(), icon: List },
    ...(page.props.can.manage
        ? [
              { title: 'Users', href: UserController.index(), icon: Users },
              {
                  title: 'GitHub access',
                  href: GithubAccessController.index(),
                  icon: KeyRound,
              },
          ]
        : []),
]);
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
            <NavMain :items="overviewItems" />
            <NavMain label="Inventory" :items="inventoryItems" />
            <NavMain label="Monitoring" :items="monitoringItems" />
            <NavMain label="Settings" :items="settingsItems" />
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
