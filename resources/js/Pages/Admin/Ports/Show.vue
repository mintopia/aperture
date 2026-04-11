<script setup>
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    port: Object,
    statistics: Object,
    portId: String,
});

function shutdownPort(portId) {
    router.post(route('admin.ports.shutdown', portId));
}

function enablePort(portId) {
    router.post(route('admin.ports.enable', portId));
}

const statColumns = [
    { key: 'metric', label: 'Metric' },
    { key: 'value', label: 'Value' },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h1
                data-testid="page-title"
                class="font-heading font-mono text-xl font-bold text-[var(--color-text)] sm:text-2xl"
            >
                {{ portId }}
            </h1>
            <div class="flex gap-2">
                <button
                    data-testid="action-shutdown"
                    class="rounded-lg bg-[var(--color-danger)] px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-danger)]/80"
                    @click="shutdownPort(portId)"
                >
                    Shutdown
                </button>
                <button
                    data-testid="action-enable"
                    class="rounded-lg bg-[var(--color-success)] px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-success)]/80"
                    @click="enablePort(portId)"
                >
                    Enable
                </button>
            </div>
        </div>

        <MetadataStrip
            :items="[
                { label: 'Status', value: port?.status ?? '—' },
                { label: 'Speed', value: port?.speed ?? '—' },
                { label: 'Duplex', value: port?.duplex ?? '—' },
                { label: 'VLAN', value: port?.vlan ?? '—' },
            ]"
        />

        <SectionHeader title="Statistics" accent-line class="mt-5" />

        <DataTable
            :columns="statColumns"
            :rows="[
                { metric: 'In Bytes', value: statistics?.in_bytes ?? '—' },
                { metric: 'Out Bytes', value: statistics?.out_bytes ?? '—' },
                { metric: 'In Errors', value: statistics?.in_errors ?? '—' },
                { metric: 'Out Errors', value: statistics?.out_errors ?? '—' },
            ]"
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-muted)]">
                    {{ row.metric }}
                </td>
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.value }}
                </td>
            </template>
        </DataTable>
    </div>
</template>
