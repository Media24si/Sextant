<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    TagsInput,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
} from '@/components/ui/tags-input';
import type { ArgumentOverrides } from '@/lib/monitors';

/*
 * Fields generated from a checker's argument schema (SPEC §4.6). The model
 * holds overrides only: an empty field inherits the registry default, which
 * shows as the placeholder (§4.5).
 */
type Field = App.Data.Monitors.ArgumentSchemaData;

const props = defineProps<{
    schema: Field[];
    errors?: Record<string, string | undefined>;
    idPrefix?: string;
}>();

const overrides = defineModel<ArgumentOverrides>({ required: true });

const id = (field: Field) => `${props.idPrefix ?? 'argument'}-${field.name}`;

const isOverridden = (field: Field) => field.name in overrides.value;

function set(field: Field, value: unknown): void {
    const next = { ...overrides.value };
    const empty =
        value === '' ||
        value === null ||
        value === undefined ||
        (Array.isArray(value) && value.length === 0);

    if (empty) {
        delete next[field.name];
    } else {
        next[field.name] = value as ArgumentOverrides[string];
    }

    overrides.value = next;
}

function plain(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value);
}

const text = (field: Field) =>
    isOverridden(field) ? String(overrides.value[field.name] ?? '') : '';

function setText(field: Field, value: string | number): void {
    const trimmed = String(value).trim();

    set(
        field,
        field.type === 'int' && trimmed !== '' ? Number(trimmed) : trimmed,
    );
}

const tags = (field: Field) =>
    isOverridden(field)
        ? (overrides.value[field.name] as unknown[]).map(String)
        : [];

function setTags(field: Field, values: unknown[]): void {
    const strings = values.map((value) => String(value).trim()).filter(Boolean);

    set(
        field,
        field.type === 'intList'
            ? strings.map(Number).filter((value) => Number.isInteger(value))
            : strings,
    );
}

const checked = (field: Field) =>
    Boolean(isOverridden(field) ? overrides.value[field.name] : field.default);

const placeholder = (field: Field) => {
    const value = plain(field.default);

    return value === '' ? (field.nullable ? 'Not set' : '') : value;
};

const fieldClasses =
    'border-input w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div
            v-for="field in schema"
            :key="field.name"
            class="grid content-start gap-1.5"
            :class="{
                'sm:col-span-2':
                    field.type === 'intList' || field.type === 'stringList',
            }"
            :data-field="field.name"
        >
            <Label :for="id(field)">{{ field.label }}</Label>

            <Input
                v-if="field.type === 'int' || field.type === 'string'"
                :id="id(field)"
                :model-value="text(field)"
                :type="field.type === 'int' ? 'number' : 'text'"
                :placeholder="placeholder(field)"
                :class="{ 'font-mono': field.type === 'string' }"
                :aria-invalid="!!errors?.[`arguments.${field.name}`]"
                @update:model-value="setText(field, $event)"
            />

            <div
                v-else-if="field.type === 'bool'"
                class="flex h-9 items-center gap-3"
            >
                <Switch
                    :id="id(field)"
                    :model-value="checked(field)"
                    @update:model-value="set(field, $event)"
                />
                <span class="text-sm text-muted-foreground">
                    {{ checked(field) ? 'Yes' : 'No' }}
                    <template v-if="!isOverridden(field)">(default)</template>
                </span>
                <button
                    v-if="isOverridden(field)"
                    type="button"
                    class="text-xs text-primary hover:underline"
                    @click="set(field, null)"
                >
                    Use default
                </button>
            </div>

            <TagsInput
                v-else-if="
                    field.type === 'intList' || field.type === 'stringList'
                "
                :model-value="tags(field)"
                add-on-paste
                add-on-blur
                @update:model-value="setTags(field, $event)"
            >
                <TagsInputItem
                    v-for="item in tags(field)"
                    :key="item"
                    :value="item"
                >
                    <TagsInputItemText class="font-mono" />
                    <TagsInputItemDelete />
                </TagsInputItem>
                <TagsInputInput
                    :id="id(field)"
                    :placeholder="
                        tags(field).length === 0 ? placeholder(field) : ''
                    "
                    class="font-mono"
                />
            </TagsInput>

            <select
                v-else-if="field.type === 'select'"
                :id="id(field)"
                :value="text(field)"
                :class="[fieldClasses, 'font-mono']"
                @change="set(field, ($event.target as HTMLSelectElement).value)"
            >
                <option value="">
                    Default{{
                        placeholder(field) ? ` (${placeholder(field)})` : ''
                    }}
                </option>
                <option
                    v-for="(optionLabel, value) in field.options ?? {}"
                    :key="value"
                    :value="value"
                >
                    {{ optionLabel }}
                </option>
            </select>

            <p v-if="field.help" class="text-xs text-muted-foreground">
                {{ field.help }}
            </p>
            <InputError :message="errors?.[`arguments.${field.name}`]" />
        </div>
    </div>
</template>
