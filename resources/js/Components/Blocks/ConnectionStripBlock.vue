<script setup>
import { computed } from 'vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const props = defineProps({
    title: { type: String, default: 'Connection Status' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const DEFAULT_FIELDS = [
    { label: 'IPv4', value: '{ipv4}' },
    { label: 'IPv6', value: '{ipv6}' },
    { label: 'MAC Address', value: '{mac}' },
    { label: 'Status', value: '{status}' },
];

const fields = computed(() => {
    const configuredFields = props.settings?.fields;
    if (Array.isArray(configuredFields) && configuredFields.length > 0) {
        return configuredFields;
    }
    return DEFAULT_FIELDS;
});

function resolveValue(template) {
    if (template === '{status}') {
        return props.blockContext.ipAllowed ? 'Online' : 'Offline';
    }
    const result = renderTemplate(template, props.blockContext);
    return result || '\u2014';
}
</script>

<template>
    <div data-testid="block-connection-strip">
        <div class="flex items-center">
            <div
                v-for="(field, index) in fields"
                :key="index"
                class="flex flex-1 flex-col gap-0.5"
                :class="index < fields.length - 1 ? 'border-r border-[var(--color-border)] pr-5' : ''"
                :style="index > 0 ? 'padding-left: 1.25rem' : ''"
                :data-testid="'connection-strip-field-' + index"
            >
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                    {{ field.label }}
                </span>
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">
                    {{ resolveValue(field.value) }}
                </span>
            </div>
        </div>
    </div>
</template>
