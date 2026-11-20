<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import GlobalSearchField from '@/components/app/GlobalSearchField.vue';
import SystemHealthPill from '@/components/app/SystemHealthPill.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';

const page = usePage();
</script>

<template>
    <header
        class="flex shrink-0 items-center gap-3 border-b bg-card px-4 py-3.5 md:px-8"
    >
        <SidebarTrigger class="-ml-1" />
        <GlobalSearchField class="min-w-0 flex-1" />
        <div class="ml-auto flex shrink-0 items-center gap-3.5">
            <div class="hidden md:block">
                <SystemHealthPill />
            </div>
            <DropdownMenu>
                <DropdownMenuTrigger
                    class="flex items-center gap-2.5 rounded-lg p-1 outline-none focus-visible:ring-2 focus-visible:ring-ring [&>div]:hidden sm:[&>div]:grid"
                    aria-label="Account menu"
                    data-test="user-menu-button"
                >
                    <UserInfo :user="page.props.auth.user" :show-role="true" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56 rounded-lg">
                    <UserMenuContent :user="page.props.auth.user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
