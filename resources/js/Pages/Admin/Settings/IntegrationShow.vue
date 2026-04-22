<script setup>
import { useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';
import { ref, onMounted } from 'vue';
import { formatRelative } from '@/utils/dates';
import { getCsrfToken } from '@/utils/webauthn';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    service: { type: Object, required: true },
});

const initialConfig = {};
props.service.fields.forEach((field) => {
    initialConfig[field.key] = props.service.config[field.key] ?? '';
});

const form = useForm({
    config: initialConfig,
});

const testingConnection = ref(false);
const testResult = ref(null);
const expandedLogIds = ref(new Set());
const remoteOptions = ref({});

async function fetchRemoteOptions(field) {
    remoteOptions.value[field.key] = {
        loading: true,
        options: remoteOptions.value[field.key]?.options || [],
        error: null,
    };

    try {
        const response = await fetch(field.remote_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(form.config),
        });
        const data = await response.json();

        if (data.error) {
            remoteOptions.value[field.key] = { loading: false, options: [], error: data.error };
            return;
        }

        const key = Object.keys(data).find((k) => Array.isArray(data[k])) || 'options';
        const items = data[key] || [];

        remoteOptions.value[field.key] = {
            loading: false,
            options: items.map((item) => ({
                value: item[field.remote_value || 'id'],
                label: item[field.remote_label || 'name'],
            })),
            error: null,
        };
    } catch {
        remoteOptions.value[field.key] = { loading: false, options: [], error: 'Failed to fetch options' };
    }
}

onMounted(() => {
    props.service.fields
        .filter((f) => f.type === 'select-remote' && f.remote_url)
        .forEach((f) => fetchRemoteOptions(f));
});

function submit() {
    form.put(route('admin.settings.integrations.service.update', props.service.id));
}

async function testConnection() {
    testingConnection.value = true;
    testResult.value = null;

    try {
        const response = await fetch(route('admin.settings.test', { service: props.service.id }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(form.config),
        });
        testResult.value = await response.json();
    } catch {
        testResult.value = { success: false, message: 'Request failed' };
    } finally {
        testingConnection.value = false;
    }
}

async function toggleCapability(capability, currentActive) {
    try {
        await fetch(route('admin.settings.capabilities.update'), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                capability,
                integration: props.service.id,
                active: !currentActive,
            }),
        });
        router.reload({ only: ['service'] });
    } catch {
        // Silently fail — reload will show current state
    }
}

function healthLabel(health) {
    if (health === null || health === undefined) {
        return 'Unknown';
    }

    return health ? 'Healthy' : 'Unhealthy';
}

function healthDotClass(health) {
    if (health === true) return 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]';
    if (health === false) return 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]';
    return 'bg-[var(--color-text-muted)]';
}

function formatCapabilityName(name) {
    return name
        .split('-')
        .filter(Boolean)
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');
}

function toggleField(key) {
    form.config[key] = form.config[key] === '1' ? '0' : '1';
}

function toggleLogOutput(logId) {
    if (expandedLogIds.value.has(logId)) {
        expandedLogIds.value.delete(logId);
    } else {
        expandedLogIds.value.add(logId);
    }
}

function testResultMessage(result) {
    return result.message ?? (result.success ? 'Connected successfully' : 'Connection failed');
}

function formatLogOutput(data) {
    try {
        const parsed = JSON.parse(data);
        return JSON.stringify(parsed, null, 2);
    } catch {
        return data;
    }
}

function formatTestOutput(output) {
    if (typeof output === 'string') return output;
    return JSON.stringify(output, null, 2);
}
</script>

<template>
    <div class="space-y-6">
            <!-- Page Header with actions -->
            <div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1
                            data-testid="page-title"
                            class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                            :style="{ fontVariationSettings: '\'opsz\' 48' }"
                        >
                            {{ service.name }}
                        </h1>
                        <span data-testid="health-status" class="mt-1.5 inline-flex items-center gap-1.5">
                            <span class="h-[7px] w-[7px] rounded-full" :class="healthDotClass(service.health)" />
                            <span class="text-[13px] text-[var(--color-text-secondary)]">
                                {{ healthLabel(service.health) }}
                            </span>
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            data-testid="action-test-connection"
                            class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition hover:bg-[var(--color-surface-hover)] disabled:opacity-50"
                            :disabled="testingConnection"
                            @click="testConnection"
                        >
                            {{ testingConnection ? 'Testing\u2026' : 'Test Connection' }}
                        </button>
                        <button
                            type="button"
                            data-testid="action-save"
                            class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-bold text-[var(--color-bg)] transition hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                            :disabled="form.processing"
                            @click="submit"
                        >
                            Save
                        </button>
                    </div>
                </div>

                <p class="mt-1 max-w-[60ch] text-[13px] leading-[1.6] text-[var(--color-text-secondary)]">
                    {{ service.description }}
                </p>
            </div>

            <!-- Configuration Form -->
            <div>
                <h2
                    class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    :style="{ fontVariationSettings: '\'opsz\' 16' }"
                >
                    Configuration
                </h2>

                <form data-testid="config-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-5 md:grid-cols-2">
                        <FormField
                            v-for="field in service.fields"
                            :key="field.key"
                            :label="field.label"
                            :name="field.key"
                            :required="field.required"
                            :error="form.errors[`config.${field.key}`]"
                        >
                            <!-- Toggle field -->
                            <template v-if="field.type === 'toggle'">
                                <button
                                    :id="field.key"
                                    type="button"
                                    role="switch"
                                    :aria-checked="form.config[field.key] === '1'"
                                    :data-testid="`field-toggle-${field.key}`"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                                    :class="
                                        form.config[field.key] === '1'
                                            ? 'bg-[var(--color-primary)]'
                                            : 'bg-[var(--color-border)]'
                                    "
                                    @click="toggleField(field.key)"
                                >
                                    <span
                                        aria-hidden="true"
                                        class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                        :class="form.config[field.key] === '1' ? 'translate-x-5' : 'translate-x-0'"
                                    />
                                </button>
                            </template>

                            <!-- Remote select field -->
                            <template v-else-if="field.type === 'select-remote'">
                                <div class="flex gap-2">
                                    <select
                                        :id="field.key"
                                        v-model="form.config[field.key]"
                                        :name="field.key"
                                        :data-testid="`field-select-${field.key}`"
                                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                                    >
                                        <option value="">
                                            {{ field.placeholder || 'Select\u2026' }}
                                        </option>
                                        <option
                                            v-for="option in remoteOptions[field.key]?.options || []"
                                            :key="option.value"
                                            :value="String(option.value)"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <button
                                        type="button"
                                        :data-testid="`field-refresh-${field.key}`"
                                        class="shrink-0 rounded-md border border-[var(--color-border-hover)] bg-transparent px-3 py-2 text-[13px] font-semibold text-[var(--color-text-secondary)] transition hover:bg-[var(--color-surface-hover)]"
                                        :disabled="remoteOptions[field.key]?.loading"
                                        @click="fetchRemoteOptions(field)"
                                    >
                                        {{ remoteOptions[field.key]?.loading ? '\u2026' : '\u21BB' }}
                                    </button>
                                </div>
                                <p v-if="remoteOptions[field.key]?.error" class="text-xs text-[var(--color-danger)]">
                                    {{ remoteOptions[field.key].error }}
                                </p>
                            </template>

                            <!-- Static select field -->
                            <template v-else-if="field.type === 'select'">
                                <select
                                    :id="field.key"
                                    v-model="form.config[field.key]"
                                    :name="field.key"
                                    :data-testid="`field-${field.key}`"
                                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                                >
                                    <option v-for="(label, value) in field.options" :key="value" :value="value">
                                        {{ label }}
                                    </option>
                                </select>
                            </template>

                            <!-- Text / URL / Password / Number field -->
                            <template v-else>
                                <input
                                    :id="field.key"
                                    v-model="form.config[field.key]"
                                    :name="field.key"
                                    :type="field.type"
                                    :placeholder="field.placeholder"
                                    :data-testid="`field-input-${field.key}`"
                                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                                />
                            </template>

                            <p v-if="field.help" class="text-xs text-[var(--color-text-muted)]">
                                {{ field.help }}
                            </p>
                        </FormField>
                    </div>

                    <div v-if="service.fields.length === 0" class="text-sm text-[var(--color-text-muted)]">
                        No saved configuration values yet.
                    </div>
                </form>
            </div>

            <!-- Test Connection Result -->
            <div
                v-if="testResult"
                data-testid="test-result-panel"
                class="rounded border p-3"
                :class="
                    testResult.success
                        ? 'border-[var(--color-success)]/20 bg-[var(--color-success)]/[0.08]'
                        : 'border-[var(--color-danger)]/20 bg-[var(--color-danger)]/[0.08]'
                "
            >
                <div class="flex items-center gap-2">
                    <span
                        class="h-[7px] w-[7px] rounded-full"
                        :class="
                            testResult.success
                                ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                : 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]'
                        "
                    />
                    <span
                        class="text-[13px] font-semibold"
                        :class="testResult.success ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'"
                    >
                        {{ testResultMessage(testResult) }}
                    </span>
                </div>
                <div
                    v-if="testResult.request_method"
                    data-testid="test-request-detail"
                    class="mt-1.5 font-mono text-[12px] text-[var(--color-text-secondary)]"
                >
                    <span class="font-semibold">{{ testResult.request_method }}</span>
                    {{ testResult.request_url }}
                    <template v-if="testResult.response_status">
                        &middot; Status
                        <span
                            :class="testResult.success ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'"
                        >
                            {{ testResult.response_status }}
                        </span>
                    </template>
                </div>
                <details v-if="testResult.output" class="mt-2" data-testid="test-output-details">
                    <summary
                        data-testid="test-output-toggle"
                        class="cursor-pointer list-none text-[11px] text-[var(--color-text-muted)]"
                    >
                        <span class="inline-block font-mono text-[10px] transition-transform">&#9656;</span>
                        Show Output
                    </summary>
                    <pre
                        data-testid="test-output-content"
                        class="mt-2 max-h-64 overflow-x-auto overflow-y-auto rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-3 font-mono text-[12px] leading-[1.7] text-[var(--color-text-secondary)]"
                        >{{ formatTestOutput(testResult.output) }}</pre
                    >
                </details>
            </div>

            <!-- Capabilities -->
            <div class="space-y-3">
                <div>
                    <h2
                        class="font-heading text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                        :style="{ fontVariationSettings: '\'opsz\' 16' }"
                    >
                        Capabilities
                    </h2>
                    <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                        Toggle which features this service actively provides.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="capability in service.capabilities"
                        :key="capability.name"
                        :data-testid="`capability-${capability.name}`"
                        type="button"
                        :class="
                            capability.active
                                ? 'border-[var(--color-primary)]/30 bg-[var(--color-primary)]/[0.14] text-[var(--color-primary)]'
                                : 'border-[var(--color-border-hover)] bg-transparent text-[var(--color-text-muted)]'
                        "
                        class="inline-flex items-center gap-2 rounded border px-3.5 py-1.5 text-[13px] font-semibold transition"
                        @click="toggleCapability(capability.name, capability.active)"
                    >
                        <span
                            class="h-2 w-2 rounded-full"
                            :class="
                                capability.active
                                    ? 'bg-[var(--color-primary)]'
                                    : 'bg-[var(--color-text-muted)] opacity-40'
                            "
                        />
                        {{ formatCapabilityName(capability.name) }}
                        <span
                            class="text-[11px] font-normal"
                            :class="capability.active ? 'text-[var(--color-text-muted)]' : ''"
                        >
                            {{ capability.active ? 'On' : 'Off' }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Connection Health Log -->
            <div class="space-y-3">
                <div>
                    <h2
                        class="font-heading text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                        :style="{ fontVariationSettings: '\'opsz\' 16' }"
                    >
                        Connection Health Log
                    </h2>
                    <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                        Recent connectivity checks for this service.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table data-testid="health-log-table" class="w-full text-[13px]">
                        <thead
                            class="text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Request</th>
                                <th class="px-4 py-3">Response</th>
                                <th class="px-4 py-3">Message</th>
                                <th class="px-4 py-3">Tested At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(log, index) in service.logs"
                                :key="log.id"
                                :data-testid="`health-log-row-${index}`"
                                class="border-t border-[var(--color-border)]"
                            >
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span
                                            class="h-[7px] w-[7px] rounded-full"
                                            :class="
                                                log.success
                                                    ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                                    : 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]'
                                            "
                                        />
                                        <span
                                            class="text-[12px] font-semibold"
                                            :class="
                                                log.success
                                                    ? 'text-[var(--color-success)]'
                                                    : 'text-[var(--color-danger)]'
                                            "
                                        >
                                            {{ log.success ? 'Success' : 'Failure' }}
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-[var(--color-text-secondary)]">
                                    <span v-if="log.request_method"
                                        >{{ log.request_method }} {{ log.request_url }}</span
                                    >
                                    <span v-else>&mdash;</span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-[var(--color-text-secondary)]">
                                    {{ log.response_status ?? '\u2014' }}
                                </td>
                                <td class="px-4 py-3 text-[var(--color-text-secondary)]">
                                    <div>{{ log.message || '\u2014' }}</div>
                                    <button
                                        v-if="log.response_data"
                                        type="button"
                                        :data-testid="`log-output-toggle-${index}`"
                                        class="mt-1 cursor-pointer text-xs text-[var(--color-text-secondary)] underline"
                                        @click="toggleLogOutput(log.id)"
                                    >
                                        {{ expandedLogIds.has(log.id) ? 'Hide Output' : 'Show Output' }}
                                    </button>
                                    <pre
                                        v-if="expandedLogIds.has(log.id) && log.response_data"
                                        :data-testid="`log-output-content-${index}`"
                                        class="mt-2 max-h-64 overflow-x-auto overflow-y-auto rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4 font-mono text-[12px] leading-[1.7] text-[var(--color-text-secondary)]"
                                        >{{ formatLogOutput(log.response_data) }}</pre
                                    >
                                </td>
                                <td class="px-4 py-3 text-[var(--color-text-secondary)]">
                                    {{ formatRelative(log.tested_at) }}
                                </td>
                            </tr>
                            <tr v-if="service.logs.length === 0" class="border-t border-[var(--color-border)]">
                                <td colspan="5" class="px-4 py-8 text-center text-[var(--color-text-muted)]">
                                    No connection tests recorded.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
    </div>
</template>
