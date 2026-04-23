<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    managed_ranges_v4: props.settings?.managed_ranges_v4 ?? '',
    managed_ranges_v6: props.settings?.managed_ranges_v6 ?? '',
    dns_filter_default: props.settings?.dns_filter_default ?? false,
});

function submit() {
    form.put(route('admin.settings.network.update'));
}
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            Network Settings
        </h1>

        <form class="space-y-4" data-testid="network-settings-form" @submit.prevent="submit">
            <!-- Managed Network Ranges -->
            <h2
                data-testid="section-heading-ranges"
                class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
            >
                Managed Network Ranges
            </h2>

            <p class="text-[13px] text-[var(--color-text-secondary)]">
                Only IPs within these ranges will be linked to users and managed by Aperture. One CIDR per line.
            </p>

            <FormField label="IPv4 Ranges" name="managed_ranges_v4" :error="form.errors.managed_ranges_v4">
                <textarea
                    id="managed_ranges_v4"
                    v-model="form.managed_ranges_v4"
                    rows="4"
                    data-testid="input-managed-ranges-v4"
                    placeholder="e.g. 10.0.0.0/8"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="IPv6 Ranges" name="managed_ranges_v6" :error="form.errors.managed_ranges_v6">
                <textarea
                    id="managed_ranges_v6"
                    v-model="form.managed_ranges_v6"
                    rows="4"
                    data-testid="input-managed-ranges-v6"
                    placeholder="e.g. fc00::/7"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <!-- Network Defaults -->
            <h2
                data-testid="section-heading-defaults"
                class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
            >
                Network Defaults
            </h2>

            <FormField label="DNS Filtering Default" name="dns_filter_default">
                <div class="flex items-center gap-3">
                    <input
                        id="dns_filter_default"
                        v-model="form.dns_filter_default"
                        type="checkbox"
                        data-testid="toggle-dns-filter-default"
                        class="h-4 w-4 cursor-pointer rounded accent-[var(--color-primary)]"
                    />
                    <label for="dns_filter_default" class="cursor-pointer text-[13px] text-[var(--color-text)]">
                        Enable DNS filtering for new connections
                    </label>
                </div>
            </FormField>

            <button
                type="submit"
                data-testid="action-save"
                :disabled="form.processing"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
            >
                Save Settings
            </button>
        </form>
    </div>
</template>
