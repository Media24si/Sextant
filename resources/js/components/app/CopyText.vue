<script setup lang="ts">
import { toast } from 'vue-sonner';

/*
 * Text that copies itself on click, e.g. a server IP to paste into `ssh`.
 */
const props = defineProps<{
    text: string;
}>();

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.text);
        toast.success(`Copied ${props.text}`);
    } catch {
        // No clipboard on plain HTTP, or the browser refused.
        toast.error(
            "The browser didn't allow copying. Select the text instead.",
        );
    }
}
</script>

<template>
    <button
        type="button"
        :title="`Copy ${text}`"
        class="cursor-copy hover:underline"
        @click="copy"
    >
        {{ text }}
    </button>
</template>
