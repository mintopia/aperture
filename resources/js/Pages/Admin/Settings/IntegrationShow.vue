<script setup>
import { useForm, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import CapabilityTag from '@/Components/UI/CapabilityTag.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import FormField from '@/Components/UI/FormField.vue';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    service: { type: Object, required: true },
});

const form = useForm({
    config: { ...props.service.config },
});

const testingConnection = ref(false);
const testResult = ref(null);

function submit() {
    form.put(route('admin.settings.integrations.service.update', props.service.id));
}

async function testConnection() {
    testingConnection.value = true;
    testResult.value = null;

    try {
        const routeName = `admin.settings.test.${props.service.id}`;
        const response = await fetch(route(routeName), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
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

function fieldLabel(key) {
    return key
        .split('_')
        .filter(Boolean)
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');
}

function testResultMessage(result) {
    return result.message ?? (result.success ? 'Connected successfully' : 'Connection failed');
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
                            v-for="(value, key) in form.config"
                            :key="key"
                            :label="fieldLabel(key)"
                            :name="key"
                            :error="form.errors[`config.${key}`]"
                        >
                            <input
                                :id="key"
                                v-model="form.config[key]"
                                :name="key"
                                type="text"
                                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                            />
                        </FormField>
                    </div>

                    <div v-if="Object.keys(form.config).length === 0" class="text-sm text-[var(--color-text-muted)]">
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

                        <p v-if="testResult" class="text-sm text-[var(--color-text-secondary)]">
                            {{ testResultMessage(testResult) }}
                        </p>
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
                                    {{ log.message || '—' }}
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
