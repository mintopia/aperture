<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import { TEMPLATE_VARIABLES, TEMPLATE_VARIABLE_GROUPS } from '@/utils/templateVariables.js';
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';

const props = defineProps({
    block: { type: Object, required: true },
});

const emit = defineEmits(['save', 'delete', 'close']);

const textTypes = ['custom_markdown', 'dns_filter'];
const templateSupportedTypes = ['custom_markdown', 'connection_strip'];

const title = ref(props.block.title);
const content = ref(props.block.content ?? '');
const isActive = ref(props.block.is_active);

// Connection strip fields
const fields = ref(
    props.block.type === 'connection_strip' ? JSON.parse(JSON.stringify(props.block.settings?.fields ?? [])) : [],
);

// DNS filter settings
const settingsLabel = ref(props.block.settings?.label ?? '');

// Map settings
const mapLat = ref(props.block.type === 'map' ? (props.block.settings?.lat ?? 51.5074) : 51.5074);
const mapLng = ref(props.block.type === 'map' ? (props.block.settings?.lng ?? -0.1278) : -0.1278);
const mapZoom = ref(props.block.type === 'map' ? (props.block.settings?.zoom ?? 13) : 13);
const mapShowTitle = ref(props.block.type === 'map' ? (props.block.settings?.showTitle ?? true) : true);

// Link strip settings
const links = ref(
    props.block.type === 'link_strip' ? JSON.parse(JSON.stringify(props.block.settings?.links ?? [])) : [],
);
const linkStripLayout = ref(
    props.block.type === 'link_strip' ? (props.block.settings?.layout ?? 'horizontal') : 'horizontal',
);

// Template variables
const variablesExpanded = ref(false);
const lastFocusedInput = ref(null);
const copiedKey = ref(null);

function onInputFocus(event) {
    lastFocusedInput.value = event.target;
}

function insertVariable(key) {
    const el = lastFocusedInput.value;
    if (el && document.contains(el)) {
        const start = el.selectionStart;
        const end = el.selectionEnd;
        const current = el.value;
        const newValue = current.substring(0, start) + key + current.substring(end);

        el.value = newValue;
        el.dispatchEvent(new Event('input', { bubbles: true }));

        const newPos = start + key.length;
        el.setSelectionRange(newPos, newPos);
        el.focus();
    } else {
        navigator.clipboard.writeText(key);
        copiedKey.value = key;
        setTimeout(() => {
            copiedKey.value = null;
        }, 1500);
    }
}

const showTemplateVariables = computed(() => templateSupportedTypes.includes(props.block.type));

// Resize logic
const MIN_WIDTH = 280;
const panelWidth = ref(320);
const isResizing = ref(false);

function onResizeStart(e) {
    isResizing.value = true;
    e.preventDefault();

    function onMouseMove(ev) {
        const maxWidth = Math.floor(window.innerWidth / 2);
        const newWidth = Math.max(MIN_WIDTH, Math.min(window.innerWidth - ev.clientX, maxWidth));
        panelWidth.value = newWidth;
    }

    function onMouseUp() {
        isResizing.value = false;
        window.removeEventListener('mousemove', onMouseMove);
        window.removeEventListener('mouseup', onMouseUp);
    }

    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
}

onBeforeUnmount(() => {
    isResizing.value = false;
});

watch(
    () => props.block,
    (b) => {
        title.value = b.title;
        content.value = b.content ?? '';
        isActive.value = b.is_active;
        fields.value = b.type === 'connection_strip' ? JSON.parse(JSON.stringify(b.settings?.fields ?? [])) : [];
        settingsLabel.value = b.settings?.label ?? '';
        mapLat.value = b.type === 'map' ? (b.settings?.lat ?? 51.5074) : 51.5074;
        mapLng.value = b.type === 'map' ? (b.settings?.lng ?? -0.1278) : -0.1278;
        mapZoom.value = b.type === 'map' ? (b.settings?.zoom ?? 13) : 13;
        mapShowTitle.value = b.type === 'map' ? (b.settings?.showTitle ?? true) : true;
        links.value = b.type === 'link_strip' ? JSON.parse(JSON.stringify(b.settings?.links ?? [])) : [];
        linkStripLayout.value = b.type === 'link_strip' ? (b.settings?.layout ?? 'horizontal') : 'horizontal';
    },
);

function addField() {
    fields.value.push({ label: '', value: '' });
}

function removeField(index) {
    fields.value.splice(index, 1);
}

function addLink() {
    links.value.push({ label: '', url: '' });
}

function removeLink(index) {
    links.value.splice(index, 1);
}

function buildSettings() {
    if (props.block.type === 'connection_strip') {
        return { fields: fields.value };
    }
    if (props.block.type === 'dns_filter') {
        return { label: settingsLabel.value };
    }
    if (props.block.type === 'map') {
        const lat = Number(mapLat.value);
        const lng = Number(mapLng.value);
        const zm = Number(mapZoom.value);
        return {
            lat: Number.isFinite(lat) ? lat : 51.5074,
            lng: Number.isFinite(lng) ? lng : -0.1278,
            zoom: Number.isFinite(zm) ? Math.round(zm) : 13,
            showTitle: mapShowTitle.value,
        };
    }
    if (props.block.type === 'link_strip') {
        return { links: links.value, layout: linkStripLayout.value };
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
        class="fixed inset-y-0 right-0 z-50 overflow-y-auto border-l border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
        :style="{ width: panelWidth + 'px' }"
    >
        <div
            data-testid="panel-resize-handle"
            class="absolute inset-y-0 left-0 w-1 cursor-col-resize hover:bg-[var(--color-accent)]/40"
            :class="isResizing ? 'bg-[var(--color-accent)]/40' : ''"
            @mousedown="onResizeStart"
        />
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

        <div v-if="textTypes.includes(block.type)" class="mb-4" data-testid="panel-content-input">
            <MarkdownEditor v-model="content" height="200px">
                <template #label>
                    <label class="text-[12px] font-semibold text-[var(--color-text-secondary)]">Content</label>
                </template>
            </MarkdownEditor>
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
                    @focus="onInputFocus"
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
                Label
            </label>
            <input
                v-model="settingsLabel"
                data-testid="panel-settings-label"
                placeholder="Text shown next to the toggle"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div v-if="block.type === 'map'" class="mb-4" data-testid="panel-map-settings">
            <label class="mb-2 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Map Settings
            </label>
            <div class="space-y-2">
                <div>
                    <label class="mb-1 block text-[11px] text-[var(--color-text-muted)]">Latitude</label>
                    <input
                        v-model.number="mapLat"
                        type="number"
                        step="any"
                        data-testid="panel-map-lat"
                        class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] text-[var(--color-text-muted)]">Longitude</label>
                    <input
                        v-model.number="mapLng"
                        type="number"
                        step="any"
                        data-testid="panel-map-lng"
                        class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] text-[var(--color-text-muted)]">Zoom</label>
                    <input
                        v-model.number="mapZoom"
                        type="number"
                        min="1"
                        max="19"
                        data-testid="panel-map-zoom"
                        class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </div>
                <div class="flex items-center justify-between">
                    <label class="text-[11px] text-[var(--color-text-muted)]">Show Title</label>
                    <button
                        data-testid="panel-map-show-title"
                        role="switch"
                        :aria-checked="mapShowTitle"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                        :class="mapShowTitle ? 'bg-[var(--color-success)]' : 'bg-[var(--color-surface-alt)]'"
                        @click="mapShowTitle = !mapShowTitle"
                    >
                        <span
                            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200"
                            :class="mapShowTitle ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </button>
                </div>
            </div>
        </div>

        <div v-if="block.type === 'link_strip'" class="mb-4" data-testid="panel-links-editor">
            <label class="mb-2 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Links
            </label>
            <div v-for="(link, index) in links" :key="index" class="mb-2 space-y-1">
                <div class="flex items-center gap-2">
                    <input
                        v-model="links[index].label"
                        placeholder="Label"
                        :data-testid="'panel-link-label-' + index"
                        class="flex-1 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1.5 text-sm text-[var(--color-text)]"
                    />
                    <button
                        :data-testid="'panel-link-remove-' + index"
                        class="text-[var(--color-danger)] hover:text-[var(--color-danger)]/80"
                        @click="removeLink(index)"
                    >
                        &#x2715;
                    </button>
                </div>
                <input
                    v-model="links[index].url"
                    placeholder="https://..."
                    :data-testid="'panel-link-url-' + index"
                    class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1.5 text-sm text-[var(--color-text)]"
                />
            </div>
            <button
                data-testid="panel-add-link"
                class="text-sm text-[var(--color-accent)] hover:underline"
                @click="addLink"
            >
                + Add Link
            </button>
            <div class="mt-3" data-testid="panel-link-strip-layout">
                <label
                    class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >
                    Layout
                </label>
                <div class="flex gap-1">
                    <button
                        data-testid="panel-layout-horizontal"
                        class="flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            linkStripLayout === 'horizontal'
                                ? 'bg-[var(--color-accent)] text-[var(--color-accent-text)]'
                                : 'bg-[var(--color-surface-alt)] text-[var(--color-text-muted)]'
                        "
                        @click="linkStripLayout = 'horizontal'"
                    >
                        Horizontal
                    </button>
                    <button
                        data-testid="panel-layout-vertical"
                        class="flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            linkStripLayout === 'vertical'
                                ? 'bg-[var(--color-accent)] text-[var(--color-accent-text)]'
                                : 'bg-[var(--color-surface-alt)] text-[var(--color-text-muted)]'
                        "
                        @click="linkStripLayout = 'vertical'"
                    >
                        Vertical
                    </button>
                </div>
            </div>
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
                        @click="insertVariable(v.key)"
                    >
                        <code class="font-mono text-[var(--color-accent)]">{{ v.key }}</code>
                        <span class="text-[var(--color-text-muted)]">{{ v.label }}</span>
                        <span
                            v-if="copiedKey === v.key"
                            :data-testid="'panel-variable-copied-' + v.key"
                            class="text-[10px] text-[var(--color-success)]"
                        >
                            Copied!
                        </span>
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
                role="switch"
                :aria-checked="isActive"
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
                class="flex-1 rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-text)]"
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
