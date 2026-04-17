<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    switchTypes: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    hostname: '',
    type: '',
    username: '',
    password: '',
    enable_password: '',
    community: '',
    port: 22,
    timeout: 5,
    enabled: true,
});

const showEnablePassword = computed(() => {
    return ['cisco_ios', 'cisco_nxos'].includes(form.type);
});

const showCommunity = computed(() => {
    return form.type === 'snmp';
});

const showCredentials = computed(() => {
    return form.type && form.type !== 'snmp';
});

function submit() {
    form.post(route('admin.switches.store'));
}
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Add Switch
        </h1>

        <form data-testid="switch-form" class="space-y-6" @submit.prevent="submit">
            <!-- Basic Info -->
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">Basic Information</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Name" name="name" :required="true" :error="form.errors.name">
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            data-testid="switch-name"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Hostname" name="hostname" :required="true" :error="form.errors.hostname">
                        <input
                            id="hostname"
                            v-model="form.hostname"
                            type="text"
                            data-testid="switch-hostname"
                            placeholder="e.g. 192.168.1.1 or switch.local"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Type" name="type" :required="true" :error="form.errors.type">
                        <select
                            id="type"
                            v-model="form.type"
                            data-testid="switch-type"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        >
                            <option value="" disabled>Select a type…</option>
                            <option v-for="t in switchTypes" :key="t.value" :value="t.value">
                                {{ t.label }}
                            </option>
                        </select>
                    </FormField>

                    <FormField label="Enabled" name="enabled" :error="form.errors.enabled">
                        <label class="flex items-center gap-2 pt-1 text-sm text-[var(--color-text)]">
                            <input
                                v-model="form.enabled"
                                type="checkbox"
                                data-testid="switch-enabled"
                                class="rounded border-[var(--color-border)]"
                            />
                            Switch is enabled
                        </label>
                    </FormField>
                </div>
            </div>

            <!-- Connection -->
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">Connection</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Port" name="port" :error="form.errors.port">
                        <input
                            id="port"
                            v-model.number="form.port"
                            type="number"
                            data-testid="switch-port"
                            min="1"
                            max="65535"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Timeout (seconds)" name="timeout" :error="form.errors.timeout">
                        <input
                            id="timeout"
                            v-model.number="form.timeout"
                            type="number"
                            data-testid="switch-timeout"
                            min="1"
                            max="120"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Credentials -->
            <div
                v-if="showCredentials"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">Credentials</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Username" name="username" :error="form.errors.username">
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            data-testid="switch-username"
                            autocomplete="off"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Password" name="password" :error="form.errors.password">
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            data-testid="switch-password"
                            autocomplete="new-password"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField
                        v-if="showEnablePassword"
                        label="Enable Password"
                        name="enable_password"
                        :error="form.errors.enable_password"
                    >
                        <input
                            id="enable_password"
                            v-model="form.enable_password"
                            type="password"
                            data-testid="switch-enable-password"
                            autocomplete="new-password"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- SNMP Community -->
            <div
                v-if="showCommunity"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">SNMP Settings</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Community String" name="community" :error="form.errors.community">
                        <input
                            id="community"
                            v-model="form.community"
                            type="text"
                            data-testid="switch-community"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    data-testid="action-save"
                    :disabled="form.processing"
                    class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save Switch' }}
                </button>
                <Link
                    :href="route('admin.switches.index')"
                    data-testid="action-cancel"
                    class="rounded-lg border border-[var(--color-border)] px-4 py-2 text-sm font-semibold text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)]"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
