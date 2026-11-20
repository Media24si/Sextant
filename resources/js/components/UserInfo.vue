<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

type Props = {
    user: User;
    showRole?: boolean;
};

withDefaults(defineProps<Props>(), {
    showRole: false,
});

const { getInitials } = useInitials();

const roleLabels: Record<App.Enums.UserRole, string> = {
    admin: 'Admin',
    reader: 'Reader',
};
</script>

<template>
    <Avatar class="size-[34px] overflow-hidden rounded-full">
        <AvatarFallback
            class="rounded-full bg-blue-100 text-[13px] font-semibold text-blue-900 dark:bg-blue-950 dark:text-blue-200"
        >
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate text-[13px] font-semibold">{{ user.name }}</span>
        <span v-if="showRole" class="truncate text-xs text-muted-foreground">{{
            roleLabels[user.role]
        }}</span>
    </div>
</template>
