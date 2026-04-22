<script setup>
import { router } from '@inertiajs/vue3';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    services: { type: Array, default: () => [] },
    capabilityDescriptions: { type: Object, default: () => ({}) },
});

function statusDotClass(enabled) {
    return enabled ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]' : 'bg-[var(--color-text-muted)]';
}

function healthDotClass(health) {
    if (health === true) return 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]';
    if (health === false) return 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]';
    return 'bg-[var(--color-text-muted)]';
}

function healthLabel(health) {
    if (health === true) return 'Healthy';
    if (health === false) return 'Unhealthy';
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
    <div class="space-y-5">
        <div>
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Integrations
            </h1>
            <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                Review service status, connection health, and active capabilities.
            </p>
        </div>

        <div class="overflow-x-auto" data-testid="integrations-table">
            <table class="w-full text-[13px]">
                <thead>
                    <tr
                        class="border-b border-[var(--color-border)] text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
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
                                    class="rounded-[3px] border border-[var(--color-border-hover)] px-1.5 py-px text-[10px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                >
                                    Read only
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1.5 font-semibold">
                                <span class="h-[7px] w-[7px] rounded-full" :class="statusDotClass(service.enabled)" />
                                <span
                                    class="text-[12px] font-semibold"
                                    :class="
                                        service.enabled
                                            ? 'text-[var(--color-success)]'
                                            : 'text-[var(--color-text-muted)]'
                                    "
                                >
                                    {{ service.enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </span>
                        </td>
                        <td class="px-4 py-3" :data-testid="`integration-health-${service.id}`">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-[7px] w-[7px] rounded-full" :class="healthDotClass(service.health)" />
                                <span class="text-[12px] text-[var(--color-text-secondary)]">
                                    {{ healthLabel(service.health) }}
                                </span>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="capability in service.capabilities"
                                    :key="capability.name"
                                    :data-testid="`integration-capability-${service.id}-${capability.name}`"
                                    :class="
                                        capability.active
                                            ? 'bg-[var(--color-primary)]/[0.14] text-[var(--color-primary)]'
                                            : 'bg-[var(--color-surface-hover)] text-[var(--color-text-muted)]'
                                    "
                                    class="rounded px-2 py-0.5 text-[11px] font-semibold"
                                >
                                    {{ capability.name }}
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-if="Object.keys(props.capabilityDescriptions).length > 0">
            <SectionHeader title="Capabilities Reference" class="mt-8" />
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-testid="capabilities-reference">
                <div
                    v-for="(description, name) in props.capabilityDescriptions"
                    :key="name"
                    :data-testid="`capability-desc-${name}`"
                    class="p-4"
                >
                    <span
                        class="inline-flex items-center rounded bg-[var(--color-primary)]/[0.14] px-2 py-0.5 text-[11px] font-semibold text-[var(--color-primary)]"
                    >
                        {{ name }}
                    </span>
                    <p class="mt-2 text-[13px] text-[var(--color-text-secondary)]">
                        {{ description }}
                    </p>
                </div>
            </div>
        </template>
    </div>
</template>
