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

function isStatusField(template) {
    return template === '{status}';
}

function resolveValue(template) {
    if (isStatusField(template)) {
        return props.blockContext.internetEnabled ? 'Online' : 'Offline';
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
                <span class="flex items-center gap-1.5 font-mono text-[13px] font-medium text-[var(--color-text)]">
                    <span
                        v-if="isStatusField(field.value)"
                        data-testid="status-dot"
                        class="inline-block size-2 shrink-0 rounded-full"
                        :class="
                            props.blockContext.internetEnabled
                                ? 'bg-[var(--color-success)]'
                                : 'bg-[var(--color-danger)]'
                        "
                    />
                    {{ resolveValue(field.value) }}
                </span>
            </div>
        </div>
    </div>
</template>
