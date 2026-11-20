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
    <!-- The Sextant sign (public/sign.svg), in the current text colour. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 58.6 58.6"
        fill="currentColor"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <path
            d="M44.6,31.6l-1.7-0.5c-0.3,0.8-0.6,1.6-1,2.3l-5.1-8.5h1v-1.7h-2l-3.6-6c1.1-0.9,1.8-2.2,1.8-3.7c0-2.3-1.7-4.3-3.9-4.7 V6.7h-1.7v2.1c-2.2,0.4-3.9,2.3-3.9,4.7c0,1.4,0.6,2.7,1.6,3.5l-3.7,6.1h-1.7v1.7h0.7L16.5,33c-0.4-0.7-0.7-1.4-0.9-2.2l-1.7,0.5 c0.4,1.3,1,2.6,1.8,3.9c2.8,4.4,7.5,7.1,12.7,7.4v4.7H24V49h4.4v3h1.7v-2.8h4.4v-1.7h-4.4v-4.7C36.8,42.4,42.5,38,44.6,31.6z M30.7,18l3.1,5.2h-3.7v-5C30.3,18.1,30.5,18.1,30.7,18z M30.1,41V24.9h4.7L40.9,35C38.4,38.5,34.5,40.7,30.1,41z M32.3,13.5 c0,1.1-0.6,2.1-1.6,2.7l0.4,0.7l0,0l-0.4-0.7c-0.2,0.1-0.4,0.2-0.5,0.2v-5.7C31.4,11,32.3,12.1,32.3,13.5z M28.4,10.6v5.7 c-1.2-0.4-2.1-1.5-2.1-2.9S27.2,11,28.4,10.6z M28.4,18.2v5h-3.9l3.1-5.2C27.9,18,28.1,18.1,28.4,18.2z M17.5,34.9l5.9-9.9h5v16 C24,40.7,20,38.5,17.5,34.9z"
        />
        <circle
            cx="29.3"
            cy="29.3"
            r="28.5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5574"
        />
    </svg>
</template>
