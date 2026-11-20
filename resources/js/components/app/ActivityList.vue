<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import EmptyState from '@/components/app/EmptyState.vue';
import RelativeTime from '@/components/app/RelativeTime.vue';

/* Change-feed entries with who and when (SPEC §8); Sextant's own have no user. */
defineProps<{
    items: App.Data.ActivityItemData[];
}>();

const categoryDots: Record<App.Enums.ActivityCategory, string> = {
    status: 'bg-status-warning',
    inventory: 'bg-status-unknown',
    detected: 'bg-primary',
};
</script>

<template>
    <ul v-if="items.length > 0" class="divide-y">
        <li
            v-for="item in items"
            :key="item.id"
            class="flex gap-3 py-3 text-sm"
        >
            <span
                class="mt-1.5 size-2 shrink-0 rounded-full"
                :class="categoryDots[item.category]"
                aria-hidden="true"
            />
            <div>
                <p>
                    <Link
                        v-if="item.url"
                        :href="item.url"
                        class="hover:underline"
                        >{{ item.summary }}</Link
                    >
                    <template v-else>{{ item.summary }}</template>
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ item.user ?? 'Sextant' }} ·
                    <RelativeTime :datetime="item.created_at" />
                </p>
            </div>
        </li>
    </ul>
    <EmptyState v-else title="No changes yet" />
</template>
