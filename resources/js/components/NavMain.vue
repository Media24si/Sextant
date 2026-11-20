<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { SidebarNavItem } from '@/types';

defineProps<{
    items: SidebarNavItem[];
    label?: string;
}>();

const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();

function isActive(item: SidebarNavItem): boolean {
    if (!item.href) {
        return false;
    }

    // Overview lives at "/", which would otherwise match every page.
    return toUrl(item.href) === '/'
        ? isCurrentUrl(item.href)
        : isCurrentOrParentUrl(item.href);
}
</script>

<template>
    <SidebarGroup class="px-3 py-0">
        <SidebarGroupLabel
            v-if="label"
            class="text-[11px] tracking-[0.08em] text-sidebar-muted-foreground uppercase"
        >
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    v-if="item.href"
                    as-child
                    size="lg"
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                    class="h-10 rounded-lg px-2.5"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuButton
                    v-else
                    size="lg"
                    aria-disabled="true"
                    :tooltip="item.title"
                    class="h-10 rounded-lg px-2.5"
                >
                    <component :is="item.icon" />
                    <span>{{ item.title }}</span>
                </SidebarMenuButton>
                <SidebarMenuBadge
                    v-if="item.href && item.count !== undefined"
                    class="top-2.5 text-xs font-normal text-sidebar-muted-foreground"
                    >{{ item.count }}</SidebarMenuBadge
                >
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
