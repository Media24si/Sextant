<script setup lang="ts">
import type { Severity } from '@/lib/status';
import { statusToneClasses } from '@/lib/status';

defineProps<{
    severity: Severity;
}>();
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase"
        :class="statusToneClasses[severity]"
    >
        {{ severity }}
    </span>
</template>
