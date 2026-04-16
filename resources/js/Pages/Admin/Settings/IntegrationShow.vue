<script setup>
import { useForm, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import CapabilityTag from '@/Components/UI/CapabilityTag.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import FormField from '@/Components/UI/FormField.vue';
import { ref, onMounted } from 'vue';

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
const showTestOutput = ref(false);
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
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
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
    showTestOutput.value = false;

    try {
        const routeName = `admin.settings.test.${props.service.id}`;
        const response = await fetch(route(routeName), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
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
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
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

function healthDot(health) {
    if (health === null || health === undefined) {
        return 'bg-[var(--color-text-muted)]';
    }

    return health ? 'bg-[var(--color-success)]' : 'bg-[var(--color-danger)]';
}

function healthLabel(health) {
    if (health === null || health === undefined) {
        return 'Unknown';
    }

    return health ? 'Healthy' : 'Unhealthy';
}

function healthStatus(health) {
    if (health === null || health === undefined) {
        return 'neutral';
    }

    return health ? 'success' : 'danger';
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
</script>

<template>
    <SettingsNav>
        <div class="space-y-6">
            <Link
                :href="route('admin.settings.integrations')"
                data-testid="back-link"
                class="inline-flex items-center text-sm text-[var(--color-text-secondary)] transition-colors hover:text-[var(--color-text)]"
            >
                ← Back to Services
            </Link>

            <div class="space-y-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <h1
                            data-testid="page-title"
                            class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                        >
                            {{ service.name }}
                        </h1>
                        <div
                            data-testid="health-status"
                            class="inline-flex items-center gap-2 text-sm text-[var(--color-text-secondary)]"
                        >
                            <span
                                class="h-2.5 w-2.5 rounded-full"
                                :class="healthDot(service.health)"
                                aria-hidden="true"
                            />
                            <span>{{ healthLabel(service.health) }}</span>
                        </div>
                    </div>

                    <StatusPill :status="healthStatus(service.health)" :label="healthLabel(service.health)" />
                </div>

                <p class="max-w-3xl text-sm text-[var(--color-text-secondary)]">
                    {{ service.description }}
                </p>
            </div>

            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
                <form data-testid="config-form" class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 md:grid-cols-2">
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
                                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                                    >
                                        <option value="">
                                            {{ field.placeholder || 'Select…' }}
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
                                        class="shrink-0 rounded-lg border border-[var(--color-border)] px-3 py-2 text-sm text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)]"
                                        :disabled="remoteOptions[field.key]?.loading"
                                        @click="fetchRemoteOptions(field)"
                                    >
                                        {{ remoteOptions[field.key]?.loading ? '…' : '↻' }}
                                    </button>
                                </div>
                                <p v-if="remoteOptions[field.key]?.error" class="text-xs text-[var(--color-danger)]">
                                    {{ remoteOptions[field.key].error }}
                                </p>
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
                                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
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

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            data-testid="action-save"
                            class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            Save
                        </button>

                        <button
                            type="button"
                            data-testid="action-test-connection"
                            class="rounded-lg border border-[var(--color-border)] px-4 py-2 text-sm font-semibold text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)] disabled:opacity-50"
                            :disabled="testingConnection"
                            @click="testConnection"
                        >
                            {{ testingConnection ? 'Testing…' : 'Test Connection' }}
                        </button>
                    </div>

                    <div v-if="testResult" class="mt-2">
                        <p
                            class="text-sm"
                            :class="testResult.success ? 'text-green-600' : 'text-red-600'"
                        >
                            {{ testResultMessage(testResult) }}
                        </p>
                        <button
                            v-if="testResult.output"
                            data-testid="test-output-toggle"
                            class="mt-1 text-xs text-[var(--color-text-secondary)] underline cursor-pointer"
                            @click="showTestOutput = !showTestOutput"
                        >
                            {{ showTestOutput ? 'Hide Output' : 'Show Output' }}
                        </button>
                        <pre
                            v-if="showTestOutput && testResult.output"
                            data-testid="test-output-content"
                            class="mt-2 p-3 text-xs font-mono bg-[var(--color-bg-secondary)] rounded overflow-x-auto max-h-64 overflow-y-auto"
                        >{{ typeof testResult.output === 'string' ? testResult.output : JSON.stringify(testResult.output, null, 2) }}</pre>
                    </div>
                </form>
            </div>

            <div class="space-y-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
                <div>
                    <h2 class="text-lg font-semibold text-[var(--color-text)]">Capabilities</h2>
                    <p class="text-sm text-[var(--color-text-secondary)]">
                        Toggle which features this service actively provides.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button
                        v-for="capability in service.capabilities"
                        :key="capability.name"
                        :data-testid="`capability-${capability.name}`"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-full border border-[var(--color-border)] px-2 py-1 transition hover:bg-[var(--color-surface-hover)]"
                        @click="toggleCapability(capability.name, capability.active)"
                    >
                        <CapabilityTag :name="capability.name" :active="capability.active" />
                        <span class="text-xs text-[var(--color-text-secondary)]">
                            {{ capability.active ? 'On' : 'Off' }}
                        </span>
                    </button>
                </div>
            </div>

            <div class="space-y-3 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
                <div>
                    <h2 class="text-lg font-semibold text-[var(--color-text)]">Connection Health Log</h2>
                    <p class="text-sm text-[var(--color-text-secondary)]">
                        Recent connectivity checks for this service.
                    </p>
                </div>

                <div class="overflow-hidden rounded-lg border border-[var(--color-border)]">
                    <table data-testid="health-log-table" class="w-full text-sm">
                        <thead
                            class="bg-[var(--color-surface-hover)] text-left text-xs tracking-wider text-[var(--color-text-muted)] uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3">Status</th>
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
                                    <StatusPill
                                        :status="log.success ? 'success' : 'danger'"
                                        :label="log.success ? 'Success' : 'Failure'"
                                    />
                                </td>
                                <td class="px-4 py-3 text-[var(--color-text-secondary)]">
                                    <div>{{ log.message || '—' }}</div>
                                    <button
                                        v-if="log.response_data"
                                        :data-testid="`log-output-toggle-${index}`"
                                        class="mt-1 text-xs text-[var(--color-text-secondary)] underline cursor-pointer"
                                        @click="toggleLogOutput(log.id)"
                                    >
                                        {{ expandedLogIds.has(log.id) ? 'Hide Output' : 'Show Output' }}
                                    </button>
                                    <pre
                                        v-if="expandedLogIds.has(log.id) && log.response_data"
                                        :data-testid="`log-output-content-${index}`"
                                        class="mt-2 p-3 text-xs font-mono bg-[var(--color-bg-secondary)] rounded overflow-x-auto max-h-64 overflow-y-auto"
                                    >{{ formatLogOutput(log.response_data) }}</pre>
                                </td>
                                <td class="px-4 py-3 text-[var(--color-text-secondary)]">
                                    {{ log.tested_at }}
                                </td>
                            </tr>
                            <tr v-if="service.logs.length === 0" class="border-t border-[var(--color-border)]">
                                <td colspan="3" class="px-4 py-8 text-center text-[var(--color-text-muted)]">
                                    No connection tests recorded.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </SettingsNav>
</template>
