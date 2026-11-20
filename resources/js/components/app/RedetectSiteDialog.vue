<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import SiteController from '@/actions/App/Http/Controllers/SiteController';
import ConfidenceBadge from '@/components/app/ConfidenceBadge.vue';
import ServingPath from '@/components/app/ServingPath.vue';
import StatusDot from '@/components/app/StatusDot.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/*
 * Re-run detection on a site (SPEC §10.7): the same findings as Add site,
 * with the current serving path beside them. Applies only what the admin
 * confirms.
 */
const props = defineProps<{
    site: App.Data.SiteData;
    frontServices: App.Data.OptionData[];
    servers: App.Data.OptionData[];
}>();

const open = defineModel<boolean>('open', { required: true });

const detector = useHttp<
    Record<string, never>,
    App.Data.Detection.SiteDetectionData
>({});
const detection = ref<App.Data.Detection.SiteDetectionData | null>(null);
const failed = ref(false);

const form = useForm({
    front_service_id: null as number | null,
    server_id: null as number | null,
});

const currentFrontId = computed(() => props.site.front_service_id);

const frontChosen = ref(false);
const backendChosen = ref(false);

async function detect(): Promise<void> {
    detection.value = null;
    failed.value = false;
    frontChosen.value = false;
    backendChosen.value = false;

    try {
        const result = await detector.post(
            SiteController.detectAgain(props.site.id).url,
        );

        detection.value = result;
        // A finding Sextant is unsure of doesn't replace what the site already has.
        form.front_service_id =
            result.front?.confidence === 'detected'
                ? (result.front.value?.service_id ?? null)
                : currentFrontId.value;
        form.server_id =
            result.backend?.value?.server_id ?? props.site.server_id;
    } catch {
        failed.value = true;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        void detect();
    }
});

const label = (options: App.Data.OptionData[], value: number | null) =>
    options.find((option) => option.id === value)?.label ?? null;

const proposed = computed<App.Data.ServingPathData>(() => ({
    front: label(props.frontServices, form.front_service_id),
    backend: label(props.servers, form.server_id),
}));

const changed = computed(
    () =>
        form.front_service_id !== currentFrontId.value ||
        form.server_id !== props.site.server_id,
);

function apply(): void {
    form.put(SiteController.updatePath(props.site.id).url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Re-run detection</DialogTitle>
                <DialogDescription>
                    What DNS and the first probe say about
                    <span class="font-mono">{{ site.hostname }}</span> now.
                    Nothing changes until you apply it.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="detector.processing"
                class="flex items-center gap-2 py-8 text-sm text-muted-foreground"
                aria-live="polite"
            >
                <Spinner /> Detecting. This takes up to 15 seconds.
            </div>
            <p v-else-if="failed" class="py-4 text-sm text-destructive">
                Detection failed. Try again in a moment.
            </p>

            <div v-else-if="detection" class="flex flex-col gap-5 text-sm">
                <div v-if="detection.dns" class="flex flex-col gap-1">
                    <span class="flex items-center gap-2 font-medium">
                        DNS
                        <ConfidenceBadge
                            :confidence="detection.dns.confidence"
                        />
                    </span>
                    <span class="font-mono text-xs break-all">{{
                        detection.dns.value.ips.join(', ')
                    }}</span>
                    <span class="text-muted-foreground">{{
                        detection.dns.explanation
                    }}</span>
                </div>

                <div v-if="detection.front" class="grid gap-1.5">
                    <span class="flex items-center gap-2">
                        <label for="redetect-front" class="font-medium"
                            >In front</label
                        >
                        <ConfidenceBadge
                            :confidence="detection.front.confidence"
                            :chosen="frontChosen"
                        />
                    </span>
                    <select
                        id="redetect-front"
                        v-model="form.front_service_id"
                        :class="fieldClasses"
                        @change="frontChosen = true"
                    >
                        <option :value="null">
                            Nothing (straight to our server)
                        </option>
                        <option
                            v-for="service in frontServices"
                            :key="service.id"
                            :value="service.id"
                        >
                            {{ service.label }} (external)
                        </option>
                    </select>
                    <span class="text-muted-foreground">{{
                        detection.front.explanation
                    }}</span>
                </div>

                <div v-if="detection.backend" class="grid gap-1.5">
                    <span class="flex items-center gap-2">
                        <label for="redetect-backend" class="font-medium"
                            >Backend server</label
                        >
                        <ConfidenceBadge
                            :confidence="detection.backend.confidence"
                            :chosen="backendChosen"
                        />
                    </span>
                    <select
                        id="redetect-backend"
                        v-model="form.server_id"
                        :class="[fieldClasses, 'font-mono']"
                        @change="backendChosen = true"
                    >
                        <option :value="null">Not assigned</option>
                        <option
                            v-for="server in servers"
                            :key="server.id"
                            :value="server.id"
                        >
                            {{ server.label }}
                        </option>
                    </select>
                    <span class="text-muted-foreground">{{
                        detection.backend.explanation
                    }}</span>
                    <InputError :message="form.errors.server_id" />
                </div>

                <dl
                    v-if="detection.probe"
                    class="flex flex-col gap-2 rounded-lg border p-3"
                >
                    <div class="flex items-center gap-3">
                        <StatusDot
                            :status="detection.probe.http_status"
                            label
                        />
                        <dt class="w-24 font-medium">HTTP</dt>
                        <dd>{{ detection.probe.http }}</dd>
                    </div>
                    <div
                        v-if="detection.probe.tls && detection.probe.tls_status"
                        class="flex items-center gap-3"
                    >
                        <StatusDot :status="detection.probe.tls_status" label />
                        <dt class="w-24 font-medium">Certificate</dt>
                        <dd>{{ detection.probe.tls }}</dd>
                    </div>
                    <div class="flex items-center gap-3">
                        <StatusDot
                            :status="detection.probe.redirect_status"
                            label
                        />
                        <dt class="w-24 font-medium">Redirect</dt>
                        <dd class="break-all">
                            {{ detection.probe.redirect }}
                        </dd>
                    </div>
                </dl>

                <dl class="grid gap-2 rounded-lg bg-muted/40 p-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <dt class="w-16 text-muted-foreground">Now</dt>
                        <dd><ServingPath :path="site.serving_path" /></dd>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <dt class="w-16 text-muted-foreground">Apply</dt>
                        <dd><ServingPath :path="proposed" /></dd>
                    </div>
                </dl>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    :disabled="!detection || !changed || form.processing"
                    @click="apply"
                >
                    {{
                        detection && !changed
                            ? 'Nothing to change'
                            : 'Apply serving path'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
