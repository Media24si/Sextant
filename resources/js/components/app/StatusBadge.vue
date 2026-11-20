<script setup lang="ts">
import StatusDot from '@/components/app/StatusDot.vue';
import { statusLabels, statusToneClasses } from '@/lib/status';

defineProps<{
    status: App.Enums.Status;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap"
        :class="statusToneClasses[status]"
    >
        <StatusDot :status="status" />
        {{ statusLabels[status] }}
    </span>
</template>
