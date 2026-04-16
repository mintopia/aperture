<script setup>
import { router } from '@inertiajs/vue3';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    services: { type: Array, default: () => [] },
});

function healthClass(health) {
    if (health === true) {
        return 'bg-[var(--color-success)]';
    }

    if (health === false) {
        return 'bg-[var(--color-danger)]';
    }

    return 'bg-[var(--color-text-muted)]';
}

function healthLabel(health) {
    if (health === true) {
        return 'Healthy';
    }

    if (health === false) {
        return 'Unhealthy';
    }

    return 'Unknown';
}

function visitService(service) {
    if (service.readonly) {
        return;
    }

    router.visit(`/admin/settings/integrations/${service.id}`);
}
</script>

<template>
    <SettingsNav>
        <div class="space-y-5">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                >
                    Integrations
                </h1>
                <p class="mt-1 text-sm text-[var(--color-text-secondary)]">
                    Review service status, connection health, and active capabilities.
                </p>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]"
                data-testid="integrations-table"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-b border-[var(--color-border)] text-left text-xs tracking-wider text-[var(--color-text-muted)] uppercase"
                        >
                            <th class="px-4 py-3">Service</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Health</th>
                            <th class="px-4 py-3">Capabilities</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="services.length === 0" data-testid="integrations-empty">
                            <td colspan="4" class="px-4 py-10 text-center text-[var(--color-text-muted)]">
                                No integrations available.
                            </td>
                        </tr>

                        <tr
                            v-for="service in services"
                            :key="service.id"
                            :data-testid="`integration-row-${service.id}`"
                            :tabindex="service.readonly ? undefined : 0"
                            :role="service.readonly ? undefined : 'link'"
                            :class="[
                                'border-b border-[var(--color-border)] last:border-b-0',
                                service.readonly
                                    ? 'bg-[var(--color-surface)]'
                                    : 'cursor-pointer transition-colors hover:bg-[var(--color-surface-hover)] focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:outline-none',
                            ]"
                            @click="visitService(service)"
                            @keydown.enter="visitService(service)"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-[var(--color-text)]">{{ service.name }}</span>
                                    <span
                                        v-if="service.readonly"
                                        class="rounded-full border border-[var(--color-border)] px-2 py-0.5 text-[10px] font-semibold tracking-wide text-[var(--color-text-muted)] uppercase"
                                    >
                                        Read only
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <StatusPill
                                    :status="service.enabled ? 'success' : 'neutral'"
                                    :label="service.enabled ? 'Enabled' : 'Disabled'"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="inline-flex items-center gap-2 text-[var(--color-text-secondary)]"
                                    :data-testid="`integration-health-${service.id}`"
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full"
                                        :class="healthClass(service.health)"
                                        aria-hidden="true"
                                    />
                                    <span>{{ healthLabel(service.health) }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="capability in service.capabilities"
                                        :key="capability.name"
                                        :data-testid="`integration-capability-${service.id}-${capability.name}`"
                                        :class="
                                            capability.active
                                                ? 'border-[var(--color-primary)]/30 bg-[var(--color-primary)]/10 text-[var(--color-primary)]'
                                                : 'border-[var(--color-border)] bg-[var(--color-surface-hover)] text-[var(--color-text-muted)]'
                                        "
                                        class="rounded-full border px-2.5 py-1 text-xs font-medium"
                                    >
                                        {{ capability.name }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </SettingsNav>
</template>
