<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/*
 * Asks before deletes and other destructive actions (SPEC §13.4). The parent
 * closes it (v-model:open) when its request finishes, so it stays open while
 * `processing` is true.
 */
const open = defineModel<boolean>('open', { required: true });

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel?: string;
        destructive?: boolean;
        processing?: boolean;
    }>(),
    {
        description: undefined,
        confirmLabel: 'Delete',
        destructive: true,
        processing: false,
    },
);

const emit = defineEmits<{
    confirm: [];
}>();
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                <AlertDialogDescription v-if="description || $slots.default">
                    <slot>{{ description }}</slot>
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="processing"
                    >Cancel</AlertDialogCancel
                >
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    data-test="confirm-dialog-confirm"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" />
                    {{ confirmLabel }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
