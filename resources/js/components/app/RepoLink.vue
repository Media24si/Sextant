<script setup lang="ts">
import { FolderGit2 } from '@lucide/vue';
import { computed } from 'vue';

/* A site's GitHub repository (SPEC §3.7): a plain repository icon, no brand logo. */
const props = defineProps<{
    url: string;
}>();

const name = computed(() => props.url.replace(/^https:\/\/github\.com\//, ''));
</script>

<template>
    <a
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1 text-xs text-primary hover:underline"
    >
        <FolderGit2 class="size-3.5 shrink-0" aria-hidden="true" />
        <span class="font-mono">{{ name }}</span>
        <span class="sr-only">(opens GitHub in a new tab)</span>
    </a>
</template>
