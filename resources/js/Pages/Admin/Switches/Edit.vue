<script setup>
import { computed, reactive, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switchConfig: { type: Object, default: () => ({}) },
    switchTypes: { type: Array, default: () => [] },
});

const form = reactive(
    useForm({
        name: props.switchConfig.name ?? '',
        hostname: props.switchConfig.hostname ?? '',
        type: props.switchConfig.type ?? '',
        username: props.switchConfig.username ?? '',
        password: '',
        enable_password: '',
        community: props.switchConfig.community ?? '',
        port: props.switchConfig.port ?? 22,
        timeout: props.switchConfig.timeout ?? 5,
        enabled: props.switchConfig.enabled ?? true,
    }),
);

const showEnablePassword = computed(() => {
    return ['cisco', 'cisco_ios', 'cisco_nxos'].includes(form.type);
});

const showCommunity = computed(() => {
    return form.type === 'snmp';
});

const showCredentials = computed(() => {
    return form.type && form.type !== 'snmp';
});

watch(
    () => form.type,
    (type) => {
        if (type === 'snmp') {
            form.username = '';
            form.password = '';
            form.enable_password = '';
        } else {
            form.community = '';
        }

        if (!['cisco', 'cisco_ios', 'cisco_nxos'].includes(type)) {
            form.enable_password = '';
        }
    },
);

function submit() {
    form.put(route('admin.switches.update', props.switchConfig.id));
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
                Edit Switch: {{ switchConfig.name }}
            </h1>
            <p class="mt-1 text-sm text-[var(--color-text-secondary)]">
                Update switch connectivity and authentication settings.
            </p>
        </div>

        <form data-testid="switch-form" class="space-y-6" @submit.prevent="submit">
            <!-- Basic Info -->
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-6">
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">Basic Information</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Name" name="name" :required="true" :error="form.errors.name">
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            data-testid="switch-name"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
                        />
                    </FormField>

                    <FormField label="Hostname" name="hostname" :required="true" :error="form.errors.hostname">
                        <input
                            id="hostname"
                            v-model="form.hostname"
                            type="text"
                            data-testid="switch-hostname"
                            placeholder="e.g. 192.168.1.1 or switch.local"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
                        />
                    </FormField>

                    <FormField label="Type" name="type" :required="true" :error="form.errors.type">
                        <select
                            id="type"
                            v-model="form.type"
                            data-testid="switch-type"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
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
                                class="peer sr-only"
                            />
                            <span
                                aria-hidden="true"
                                class="relative inline-flex h-[18px] w-8 flex-shrink-0 rounded-full bg-[var(--color-surface-hover)] transition-colors peer-checked:bg-[var(--color-success)]"
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 h-[14px] w-[14px] rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-[14px]"
                                />
                            </span>
                            Switch is enabled
                        </label>
                    </FormField>
                </div>
            </div>

            <!-- Connection -->
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-6">
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
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
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
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Credentials -->
            <div
                v-if="showCredentials"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-6"
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
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
                        />
                    </FormField>

                    <FormField label="Password" name="password" :error="form.errors.password">
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            data-testid="switch-password"
                            autocomplete="new-password"
                            placeholder="Leave blank to keep current"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
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
                            placeholder="Leave blank to keep current"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
                        />
                    </FormField>
                </div>
            </div>

            <!-- SNMP Community -->
            <div
                v-if="showCommunity"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 sm:p-6"
            >
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">SNMP Settings</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Community String" name="community" :error="form.errors.community">
                        <input
                            id="community"
                            v-model="form.community"
                            type="text"
                            data-testid="switch-community"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/30"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap items-center gap-3 pt-1">
                <button
                    type="submit"
                    data-testid="action-save"
                    :disabled="form.processing"
                    class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--color-primary-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-bg)] focus-visible:outline-none disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save Changes' }}
                </button>
                <Link
                    :href="route('admin.switches.show', switchConfig.id)"
                    data-testid="action-cancel"
                    class="rounded-lg border border-[var(--color-border)] px-4 py-2 text-sm font-semibold text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-bg)] focus-visible:outline-none"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
