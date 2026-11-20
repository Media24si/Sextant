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
    <Avatar class="h-8 w-8 overflow-hidden rounded-lg">
        <AvatarFallback class="rounded-lg text-black dark:text-white">
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span v-if="showRole" class="truncate text-xs text-muted-foreground">{{
            roleLabels[user.role]
        }}</span>
    </div>
</template>
