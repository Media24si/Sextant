<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import ServerVersionsController from '@/actions/App/Http/Controllers/ServerVersionsController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/datetime';

/*
 * "Test connection" (SPEC §11.4): fetches the versions file now and shows
 * what it holds, or why it failed. Stores nothing.
 */
const props = defineProps<{ serverId: number }>();

type Result =
    | { ok: false; error: string }
    | {
          ok: true;
          generated_at: string;
          hostname: string | null;
          readings: {
              software: string;
              version: string;
              package: string | null;
          }[];
      };

const http = useHttp<Record<string, never>, Result>({});
const result = ref<Result | null>(null);

async function test(): Promise<void> {
    result.value = null;

    try {
        result.value = await http.post(
            ServerVersionsController.test(props.serverId).url,
        );
    } catch {
        result.value = { ok: false, error: 'The request failed.' };
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
            @click="test"
        >
            <Spinner v-if="http.processing" />
            Test connection
        </Button>
        <p
            v-if="result && !result.ok"
            class="text-sm text-destructive"
            role="alert"
        >
            {{ result.error }}
        </p>
        <div
            v-else-if="result && result.ok"
            class="rounded-lg border bg-muted/40 p-3 text-sm"
            role="status"
        >
            <p class="font-medium text-status-ok-fg">
                Connected. File written {{ formatDateTime(result.generated_at)
                }}<template v-if="result.hostname">
                    on {{ result.hostname }}</template
                >.
            </p>
            <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                <li v-for="reading in result.readings" :key="reading.software">
                    {{ reading.software }}
                    <span class="font-mono">{{ reading.version }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
