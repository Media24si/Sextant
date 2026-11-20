<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

/*
 * Label, control (default slot) and validation error for one form field.
 * Give the control the same `id` as this field's `for`.
 */
defineProps<{
    label: string;
    for: string;
    error?: string;
    description?: string;
    required?: boolean;
}>();
</script>

<template>
    <div class="grid gap-2">
        <Label :for="$props.for">
            {{ label }}
            <span v-if="required" class="text-destructive" aria-hidden="true"
                >*</span
            >
        </Label>
        <slot />
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
        <InputError :message="error" />
    </div>
</template>
