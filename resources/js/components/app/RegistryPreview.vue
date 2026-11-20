<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '@/lib/datetime';

/* What the registry says about a new domain (SPEC §10.1 step 3, §10.5). */
const props = defineProps<{
    registry: App.Data.Detection.RegistryPreviewData;
}>();

const daysLeft = computed(() => {
    if (props.registry.expires_at === null) {
        return null;
    }

    const today = new Date(
        new Date().toLocaleDateString('en-CA', {
            timeZone: 'Europe/Ljubljana',
        }),
    );

    return Math.round(
        (new Date(props.registry.expires_at).getTime() - today.getTime()) /
            86_400_000,
    );
});

const source = computed(() =>
    [props.registry.source.toUpperCase(), props.registry.server]
        .filter(Boolean)
        .join(' · '),
);
</script>

<template>
    <div class="rounded-lg border bg-muted/40 p-4 text-sm">
        <p v-if="registry.error" class="text-muted-foreground">
            The registry couldn't be asked: {{ registry.error }}. Expiry is
            checked again once the domain is saved.
        </p>
        <p v-else-if="!registry.found" class="text-status-warning-fg">
            The registry has no record of this domain. Check the spelling.
        </p>
        <dl v-else class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Source</dt>
                <dd>{{ source }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Registrar</dt>
                <dd>{{ registry.registrar ?? 'Not given' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Expires</dt>
                <dd>
                    <template v-if="registry.expires_at">
                        {{ formatDate(registry.expires_at) }} ·
                        {{ daysLeft }} days
                    </template>
                    <template v-else>Not given</template>
                </dd>
            </div>
            <div v-if="registry.nameservers.length > 0" class="sm:col-span-2">
                <dt class="text-muted-foreground">Nameservers</dt>
                <dd class="font-mono text-xs leading-5">
                    {{ registry.nameservers.join(', ') }}
                </dd>
            </div>
            <div v-if="registry.statuses.length > 0">
                <dt class="text-muted-foreground">Registry status</dt>
                <dd>{{ registry.statuses.join(', ') }}</dd>
            </div>
        </dl>
    </div>
</template>
