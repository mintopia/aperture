<script setup>
import { ref, computed, reactive, watch } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

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

const showDeleteModal = ref(false);
const deleting = ref(false);

function confirmDelete() {
    deleting.value = true;
    router.delete(route('admin.switches.destroy', props.switchConfig.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Edit Switch
            </h1>
        </div>
        <form data-testid="switch-form" class="mt-6 space-y-8" @submit.prevent="submit">
            <!-- Basic Info -->
            <div class="space-y-4">
                <h2
                    class="font-heading mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    :style="{ fontVariationSettings: '\'opsz\' 16' }"
                >
                    Basic Information
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Name" name="name" :required="true" :error="form.errors.name">
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            data-testid="switch-name"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Hostname" name="hostname" :required="true" :error="form.errors.hostname">
                        <input
                            id="hostname"
                            v-model="form.hostname"
                            type="text"
                            data-testid="switch-hostname"
                            placeholder="e.g. 192.168.1.1 or switch.local"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Type" name="type" :required="true" :error="form.errors.type">
                        <select
                            id="type"
                            v-model="form.type"
                            data-testid="switch-type"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
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
            <div class="space-y-4">
                <h2
                    class="font-heading mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    :style="{ fontVariationSettings: '\'opsz\' 16' }"
                >
                    Connection
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Port" name="port" :error="form.errors.port">
                        <input
                            id="port"
                            v-model.number="form.port"
                            type="number"
                            data-testid="switch-port"
                            min="1"
                            max="65535"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
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
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Credentials -->
            <div v-if="showCredentials" class="space-y-4">
                <h2
                    class="font-heading mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    :style="{ fontVariationSettings: '\'opsz\' 16' }"
                >
                    Credentials
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Username" name="username" :error="form.errors.username">
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            data-testid="switch-username"
                            autocomplete="off"
                            placeholder="Leave blank to keep current"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
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
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
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
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- SNMP Community -->
            <div v-if="showCommunity" class="space-y-4">
                <h2
                    class="font-heading mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    :style="{ fontVariationSettings: '\'opsz\' 16' }"
                >
                    SNMP Settings
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Community String" name="community" :error="form.errors.community">
                        <input
                            id="community"
                            v-model="form.community"
                            type="text"
                            data-testid="switch-community"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap items-center gap-3 pt-4">
                <button
                    type="submit"
                    data-testid="action-save"
                    :disabled="form.processing"
                    class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving…' : 'Save Changes' }}
                </button>
                <Link
                    :href="route('admin.switches.show', switchConfig.id)"
                    data-testid="action-cancel"
                    class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
                >
                    Cancel
                </Link>
            </div>
        </form>

        <div data-testid="danger-zone-switch" class="mt-8 border-t border-[var(--color-border)] pt-6">
            <p class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-danger)] uppercase">
                Danger Zone
            </p>
            <p class="text-[13px] text-[var(--color-text-secondary)]">
                Deleting this switch removes it and all associated port data.
            </p>
            <button
                data-testid="action-delete"
                title="Remove this switch and all its data"
                class="mt-3 rounded-md border border-[var(--color-danger)]/40 px-4 py-[7px] text-[13px] font-semibold text-[var(--color-danger)] transition-colors hover:border-[var(--color-danger)] hover:bg-[var(--color-danger)]/12"
                @click="showDeleteModal = true"
            >
                Delete Switch
            </button>
        </div>

        <ConfirmModal
            :show="showDeleteModal"
            title="Delete Switch"
            :message="`Are you sure you want to delete ${switchConfig.name}? This will remove the switch and all associated port data. This action cannot be undone.`"
            confirm-label="Delete Switch"
            variant="danger"
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="showDeleteModal = false"
        />
    </div>
</template>
