<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import ServerVersionsController from '@/actions/App/Http/Controllers/ServerVersionsController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/datetime';

/*
 * "Copy install command" (SPEC §11.8): a `curl … | sudo bash` line with a
 * signed link to the server's installer, which sets up or updates the
 * collector, its cron and versions.php. Generates the token when there's none.
 */
const props = withDefaults(
    defineProps<{ serverId: number; label?: string }>(),
    { label: 'Copy install command' },
);

const emit = defineEmits<{ tokenMasked: [masked: string] }>();

type Result = App.Data.VersionsInstallCommandData;

const http = useHttp<Record<string, never>, Result>({});
const result = ref<Result | null>(null);
const copied = ref(false);
const failed = ref(false);

async function copy(): Promise<void> {
    failed.value = false;

    try {
        result.value = await http.post(
            ServerVersionsController.installCommand(props.serverId).url,
        );
    } catch {
        failed.value = true;

        return;
    }

    emit('tokenMasked', result.value.token_masked);
    await copyAgain();
}

async function copyAgain(): Promise<void> {
    if (!result.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(result.value.command);
        copied.value = true;
    } catch {
        // No clipboard (plain HTTP, or the browser refused): the command is shown to copy by hand.
        copied.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="self-start"
            :disabled="http.processing"
            @click="copy"
        >
            <Spinner v-if="http.processing" />
            {{ label }}
        </Button>
        <p v-if="failed" class="text-sm text-destructive" role="alert">
            The request failed.
        </p>
        <div
            v-if="result"
            class="flex flex-col gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
            role="status"
        >
            <span class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{
                    copied
                        ? 'Copied. Paste it on the server:'
                        : 'Run on the server:'
                }}</span>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-7"
                    @click="copyAgain"
                    >Copy again</Button
                >
            </span>
            <code
                class="rounded bg-card px-2 py-1 font-mono text-xs break-all"
                >{{ result.command }}</code
            >
            <span class="text-muted-foreground">
                The link works until {{ formatDateTime(result.expires_at) }}.
                Running it again later updates the collector and versions.php.
            </span>
        </div>
    </div>
</template>
