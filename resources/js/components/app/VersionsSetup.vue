<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ServerVersionsController from '@/actions/App/Http/Controllers/ServerVersionsController';
import ConfirmDialog from '@/components/app/ConfirmDialog.vue';
import FormField from '@/components/app/FormField.vue';
import VersionsTestConnection from '@/components/app/VersionsTestConnection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/*
 * Software versions setup on the server form (SPEC §11.2–§11.4, §13.6): the
 * endpoint URL, the token (shown once), the two files to install, the nginx
 * snippet and Test connection.
 */
const props = defineProps<{
    serverId: number | null;
    hostname: string | null;
    tokenMasked: string | null;
    defaultPath: string;
    error?: string;
}>();

const url = defineModel<string>('url', { required: true });

const http = useHttp<Record<string, never>, { token: string; last4: string }>(
    {},
);
const token = ref<string | null>(null);
const masked = ref(props.tokenMasked);
const confirmingRotate = ref(false);
const copied = ref(false);

async function generate(): Promise<void> {
    confirmingRotate.value = false;

    if (props.serverId === null) {
        return;
    }

    const result = await http.post(
        ServerVersionsController.token(props.serverId).url,
    );

    token.value = result.token;
    masked.value = `sxt_••••${result.last4}`;
    copied.value = false;
}

async function copy(): Promise<void> {
    if (token.value) {
        await navigator.clipboard.writeText(token.value);
        copied.value = true;
    }
}

const placeholder = computed(
    () => `https://${props.hostname || 'web02.media24.si'}${props.defaultPath}`,
);

const cron = `install -m 0755 sextant-collect.sh /usr/local/bin/sextant-collect
echo '17 * * * * root /usr/local/bin/sextant-collect' > /etc/cron.d/sextant
/usr/local/bin/sextant-collect`;

const nginx = computed(
    () => `location = ${props.defaultPath} {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/sextant/versions.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # adjust to the server's PHP-FPM socket
}`,
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <p class="text-sm text-muted-foreground">
            Sextant never logs in to servers. A small collector writes the
            versions to a file every hour, and a PHP endpoint hands that file to
            Sextant over HTTPS with a token. The collector needs Linux; it can't
            run on Windows servers.
        </p>

        <FormField
            label="Versions endpoint URL"
            for="versions_url"
            :error="error"
            description="HTTPS address of versions.php on this server. Empty = versions aren't read."
        >
            <Input
                id="versions_url"
                v-model="url"
                class="font-mono"
                :placeholder="placeholder"
                :aria-invalid="!!error"
            />
        </FormField>

        <p
            v-if="serverId === null"
            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
        >
            Save the server first; then generate its token and download the two
            files here.
        </p>

        <template v-else>
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">Bearer token</span>
                <div class="flex flex-wrap items-center gap-3">
                    <span v-if="masked" class="font-mono text-sm">{{
                        masked
                    }}</span>
                    <span v-else class="text-sm text-muted-foreground"
                        >No token yet.</span
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="http.processing"
                        @click="masked ? (confirmingRotate = true) : generate()"
                        >{{
                            masked ? 'Rotate token' : 'Generate token'
                        }}</Button
                    >
                </div>
                <div
                    v-if="token"
                    class="flex flex-col gap-2 rounded-lg border border-status-warning/40 bg-status-warning-bg/40 p-3 text-sm"
                    role="status"
                >
                    <span class="font-medium"
                        >Copy it now: it isn't shown again.</span
                    >
                    <span class="flex flex-wrap items-center gap-2">
                        <code
                            class="rounded bg-card px-2 py-1 font-mono break-all"
                            >{{ token }}</code
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="copy"
                            >{{ copied ? 'Copied' : 'Copy' }}</Button
                        >
                    </span>
                    <span class="text-muted-foreground">
                        The token goes in Sextant only; versions.php holds just
                        its hash. Download versions.php again after rotating.
                    </span>
                </div>
            </div>

            <ol class="flex list-decimal flex-col gap-3 pl-5 text-sm">
                <li>
                    Download
                    <a
                        :href="ServerVersionsController.collector(serverId).url"
                        class="text-primary underline"
                        >sextant-collect.sh</a
                    >
                    and install it as root, with an hourly cron:
                    <pre
                        class="mt-1.5 overflow-x-auto rounded-lg bg-muted/50 p-3 font-mono text-xs"
                        >{{ cron }}</pre>
                </li>
                <li>
                    Download
                    <a
                        v-if="masked"
                        :href="ServerVersionsController.endpoint(serverId).url"
                        class="text-primary underline"
                        >versions.php</a
                    >
                    <span v-else class="text-muted-foreground"
                        >versions.php (after generating a token)</span
                    >
                    and save it as
                    <code class="font-mono">/var/www/sextant/versions.php</code
                    >.
                </li>
                <li>
                    Add to the server's HTTPS nginx site and reload nginx:
                    <pre
                        class="mt-1.5 overflow-x-auto rounded-lg bg-muted/50 p-3 font-mono text-xs"
                        >{{ nginx }}</pre>
                </li>
                <li>Save this form with the endpoint URL, then test:</li>
            </ol>

            <VersionsTestConnection :server-id="serverId" />
        </template>

        <ConfirmDialog
            v-model:open="confirmingRotate"
            title="Rotate the token?"
            confirm-label="Rotate token"
            @confirm="generate"
        >
            The server's current versions.php stops working until you replace it
            with a new download.
        </ConfirmDialog>
    </div>
</template>
