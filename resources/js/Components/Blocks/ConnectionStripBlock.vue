<script setup>
import { computed, ref } from 'vue';
import { renderTemplate } from '@/utils/contentTemplating.js';
import { DEFAULT_FIELDS } from '@/utils/connectionStripDefaults.js';

const props = defineProps({
    title: { type: String, default: 'Connection Status' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const internetOverride = ref(null);

const fields = computed(() => {
    const configuredFields = props.settings?.fields;
    if (Array.isArray(configuredFields) && configuredFields.length > 0) {
        return configuredFields;
    }
    return DEFAULT_FIELDS;
});

const isInternetEnabled = computed(() => {
    if (internetOverride.value !== null) {
        return internetOverride.value;
    }
    return props.blockContext.internetEnabled;
});

function isStatusField(template) {
    return template === '{status}';
}

function resolveValue(template) {
    if (isStatusField(template)) {
        return isInternetEnabled.value ? 'Online' : 'Offline';
    }
    const result = renderTemplate(template, props.blockContext);
    return result || '\u2014';
}

function updateInternetStatus(enabled) {
    internetOverride.value = enabled;
}

defineExpose({ updateInternetStatus });
</script>

<template>
    <div data-testid="block-connection-strip">
        <div class="grid grid-cols-2 gap-x-5 gap-y-3 sm:flex sm:items-center sm:gap-0">
            <div
                v-for="(field, index) in fields"
                :key="index"
                class="flex min-w-0 flex-1 flex-col gap-0.5"
                :class="[
                    index < fields.length - 1 ? 'sm:border-r sm:border-[var(--color-border)] sm:pr-5' : '',
                    index > 0 ? 'sm:pl-5' : '',
                ]"
                :data-testid="'connection-strip-field-' + index"
            >
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                    {{ field.label }}
                </span>
                <span
                    class="flex items-center gap-1.5 font-mono text-[13px] font-medium break-all text-[var(--color-text)]"
                >
                    <span
                        v-if="isStatusField(field.value)"
                        data-testid="status-dot"
                        class="inline-block size-2 shrink-0 rounded-full"
                        :class="isInternetEnabled ? 'bg-[var(--color-success)]' : 'bg-[var(--color-danger)]'"
                    />
                    {{ resolveValue(field.value) }}
                </span>
            </div>
        </div>
    </div>
</template>
