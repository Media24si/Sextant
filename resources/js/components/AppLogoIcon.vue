<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Sextant mark: a compass (docs/design). -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linejoin="round"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <circle cx="12" cy="12" r="9" fill="none" />
        <path d="M15.5 8.5l-2 5-5 2 2-5z" fill="none" />
    </svg>
</template>
