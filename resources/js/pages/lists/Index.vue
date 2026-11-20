<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ChoiceListEditor from '@/components/app/ChoiceListEditor.vue';
import PageHeader from '@/components/app/PageHeader.vue';

/* OS and backup location lists (SPEC §3.4, §13.6). */
defineProps<{
    lists: Record<App.Enums.ChoiceList, App.Data.ChoiceRowData[]>;
}>();
</script>

<template>
    <Head title="Lists" />

    <div
        class="flex w-full max-w-[1280px] flex-col gap-6 px-4 pt-7 pb-12 md:px-8"
    >
        <PageHeader
            title="Lists"
            description="The choices on the server form. Entries in use can be renamed but not deleted."
        />

        <div class="grid items-start gap-6 lg:grid-cols-2">
            <ChoiceListEditor
                list="os"
                title="Operating systems"
                description="The OS an admin sets on a server. The versions endpoint also reports it."
                :items="lists.os"
            />
            <ChoiceListEditor
                list="backup_location"
                title="Backup locations"
                description='Where a server&apos;s backups are stored. Servers with none show "None set" in orange.'
                :items="lists.backup_location"
            />
        </div>
    </div>
</template>
