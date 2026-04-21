<script setup>
import { ref, watch, computed } from 'vue';
import { TEMPLATE_VARIABLES, TEMPLATE_VARIABLE_GROUPS } from '@/utils/templateVariables.js';

const props = defineProps({
    block: { type: Object, required: true },
});

const emit = defineEmits(['save', 'delete', 'close']);

const textTypes = ['custom_markdown'];
const templateSupportedTypes = ['custom_markdown', 'connection_strip'];

const title = ref(props.block.title);
const content = ref(props.block.content ?? '');
const isActive = ref(props.block.is_active);

// Connection strip fields
const fields = ref(
    props.block.type === 'connection_strip' ? JSON.parse(JSON.stringify(props.block.settings?.fields ?? [])) : [],
);

// DNS filter settings
const settingsTitle = ref(props.block.settings?.title ?? '');
const settingsDescription = ref(props.block.settings?.description ?? '');

// Template variables
const variablesExpanded = ref(false);

const showTemplateVariables = computed(() => templateSupportedTypes.includes(props.block.type));

watch(
    () => props.block,
    (b) => {
        title.value = b.title;
        content.value = b.content ?? '';
        isActive.value = b.is_active;
        fields.value = b.type === 'connection_strip' ? JSON.parse(JSON.stringify(b.settings?.fields ?? [])) : [];
        settingsTitle.value = b.settings?.title ?? '';
        settingsDescription.value = b.settings?.description ?? '';
    },
);

function addField() {
    fields.value.push({ label: '', value: '' });
}

function removeField(index) {
    fields.value.splice(index, 1);
}

function buildSettings() {
    if (props.block.type === 'connection_strip') {
        return { fields: fields.value };
    }
    if (props.block.type === 'dns_filter') {
        return { title: settingsTitle.value, description: settingsDescription.value };
    }
    return props.block.settings;
}

function save() {
    emit('save', {
        id: props.block.id,
        title: title.value,
        content: content.value,
        is_active: isActive.value,
        settings: buildSettings(),
    });
}
</script>

<template>
    <div
        data-testid="editor-side-panel"
        class="fixed inset-y-0 right-0 z-50 w-80 overflow-y-auto border-l border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
    >
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[var(--color-text)]">Edit Block</h3>
            <button
                data-testid="panel-close"
                class="text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                @click="emit('close')"
            >
                &#x2715;
            </button>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Block Type
            </label>
            <span class="text-[13px] text-[var(--color-text-secondary)]">{{ block.type }}</span>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Title
            </label>
            <input
                v-model="title"
                data-testid="panel-title-input"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div v-if="textTypes.includes(block.type)" class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Content
            </label>
            <textarea
                v-model="content"
                data-testid="panel-content-input"
                rows="4"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div v-if="block.type === 'connection_strip'" class="mb-4" data-testid="panel-fields-editor">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Fields
            </label>
            <div v-for="(field, index) in fields" :key="index" class="mb-2 flex items-center gap-2">
                <input
                    v-model="fields[index].label"
                    placeholder="Label"
                    :data-testid="'panel-field-label-' + index"
                    class="w-1/3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1.5 text-sm text-[var(--color-text)]"
                />
                <input
                    v-model="fields[index].value"
                    placeholder="{ipv4}"
                    :data-testid="'panel-field-value-' + index"
                    class="flex-1 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1.5 font-mono text-sm text-[var(--color-text)]"
                />
                <button
                    :data-testid="'panel-field-remove-' + index"
                    class="text-[var(--color-danger)] hover:text-[var(--color-danger)]/80"
                    @click="removeField(index)"
                >
                    &#x2715;
                </button>
            </div>
            <button
                data-testid="panel-add-field"
                class="text-sm text-[var(--color-accent)] hover:underline"
                @click="addField"
            >
                + Add Field
            </button>
        </div>

        <div v-if="block.type === 'dns_filter'" class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Display Title
            </label>
            <input
                v-model="settingsTitle"
                data-testid="panel-settings-title"
                class="mb-3 w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Display Description
            </label>
            <input
                v-model="settingsDescription"
                data-testid="panel-settings-description"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div v-if="showTemplateVariables" class="mb-4" data-testid="panel-template-variables">
            <button
                data-testid="panel-variables-toggle"
                class="flex w-full items-center justify-between text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                @click="variablesExpanded = !variablesExpanded"
            >
                Available Variables
                <span class="text-xs">{{ variablesExpanded ? '&#9650;' : '&#9660;' }}</span>
            </button>
            <div v-if="variablesExpanded" class="mt-2 space-y-1">
                <template v-for="group in TEMPLATE_VARIABLE_GROUPS" :key="group">
                    <div
                        class="mt-2 text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)]/60 uppercase"
                    >
                        {{ group }}
                    </div>
                    <button
                        v-for="v in TEMPLATE_VARIABLES.filter((tv) => tv.group === group)"
                        :key="v.key"
                        class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs hover:bg-[var(--color-surface-alt)]"
                        :data-testid="'panel-variable-' + v.key"
                        @click="() => {}"
                    >
                        <code class="font-mono text-[var(--color-accent)]">{{ v.key }}</code>
                        <span class="text-[var(--color-text-muted)]">{{ v.label }}</span>
                    </button>
                </template>
            </div>
        </div>

        <div class="mb-4 flex items-center justify-between">
            <label class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Active
            </label>
            <button
                data-testid="panel-active-toggle"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                :class="isActive ? 'bg-[var(--color-success)]' : 'bg-[var(--color-surface-alt)]'"
                @click="isActive = !isActive"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200"
                    :class="isActive ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>

        <div class="flex gap-2">
            <button
                data-testid="panel-save"
                class="flex-1 rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
                @click="save"
            >
                Save Block
            </button>
            <button
                data-testid="panel-delete"
                class="rounded-md border border-[var(--color-danger)]/30 px-3 py-2 text-sm text-[var(--color-danger)]"
                @click="emit('delete', block.id)"
            >
                Delete
            </button>
        </div>
    </div>
</template>
