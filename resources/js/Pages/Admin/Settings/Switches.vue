<script setup>
import { useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    switches: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    hostname: '',
    type: 'cisco',
    username: '',
    password: '',
    enable_password: '',
    port: 22,
    timeout: 5,
});

function addSwitch() {
    form.post(route('admin.settings.switches.store'), {
        onSuccess: () => form.reset(),
    });
}

function deleteSwitch(id) {
    if (confirm('Are you sure you want to remove this switch?')) {
        router.delete(route('admin.settings.switches.destroy', { switchConfig: id }));
    }
}
</script>

<template>
    <SettingsNav>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Switch Configuration
        </h1>

        <!-- Existing Switches -->
        <div
            v-if="switches.length > 0"
            class="mb-6 overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]"
            data-testid="switch-table"
        >
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b border-[var(--color-border)] text-left text-xs text-[var(--color-text-secondary)]"
                    >
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Hostname</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="sw in switches"
                        :key="sw.id"
                        :data-testid="'switch-row-' + sw.id"
                        class="border-b border-[var(--color-border)] last:border-0"
                    >
                        <td class="px-4 py-3 text-[var(--color-text)]">{{ sw.name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-[var(--color-text-secondary)]">
                            {{ sw.hostname }}
                        </td>
                        <td class="px-4 py-3 text-[var(--color-text-secondary)]">
                            {{ sw.type }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                :class="sw.enabled ? 'text-[var(--color-success)]' : 'text-[var(--color-text-muted)]'"
                            >
                                {{ sw.enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                :data-testid="'action-delete-switch-' + sw.id"
                                class="text-xs text-[var(--color-danger)] hover:underline"
                                @click="deleteSwitch(sw.id)"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Add Switch Form -->
        <form
            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            data-testid="switch-add-form"
            @submit.prevent="addSwitch"
        >
            <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">Add Switch</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField label="Name" name="name" :error="form.errors.name">
                    <input
                        v-model="form.name"
                        type="text"
                        data-testid="switch-name"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
                <FormField label="Hostname" name="hostname" :error="form.errors.hostname">
                    <input
                        v-model="form.hostname"
                        type="text"
                        data-testid="switch-hostname"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
                <FormField label="Type" name="type" :error="form.errors.type">
                    <select
                        v-model="form.type"
                        data-testid="switch-type"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    >
                        <option value="cisco">Cisco</option>
                    </select>
                </FormField>
                <FormField label="Username" name="username" :error="form.errors.username">
                    <input
                        v-model="form.username"
                        type="text"
                        data-testid="switch-username"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
                <FormField label="Password" name="password" :error="form.errors.password">
                    <input
                        v-model="form.password"
                        type="password"
                        data-testid="switch-password"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
                <FormField label="Enable Password" name="enable_password" :error="form.errors.enable_password">
                    <input
                        v-model="form.enable_password"
                        type="password"
                        data-testid="switch-enable-password"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
                <FormField label="SSH Port" name="port" :error="form.errors.port">
                    <input
                        v-model.number="form.port"
                        type="number"
                        data-testid="switch-port"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
                <FormField label="Timeout (s)" name="timeout" :error="form.errors.timeout">
                    <input
                        v-model.number="form.timeout"
                        type="number"
                        data-testid="switch-timeout"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                    />
                </FormField>
            </div>
            <button
                type="submit"
                data-testid="action-add-switch"
                :disabled="form.processing"
                class="mt-4 rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-hover)]"
            >
                Add Switch
            </button>
        </form>
    </SettingsNav>
</template>
