<script setup>
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

defineProps({
    user: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    ips: { type: Array, default: () => [] },
    auths: { type: Array, default: () => [] },
    downloaded: { type: Number, default: 0 },
    uploaded: { type: Number, default: 0 },
});

function toggleBlock(user) {
    router.post(route('admin.users.block', user.id), {
        block: user.blocked ? 0 : 1,
    });
}

const ipColumns = [
    { key: 'address', label: 'Address' },
    { key: 'status', label: 'Status' },
    { key: 'last_seen', label: 'Last Seen' },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
                {{ user.nickname }}
            </h1>
            <button
                :data-testid="user.blocked ? 'action-unblock' : 'action-block'"
                :class="user.blocked ? 'bg-[var(--color-success)]' : 'bg-[var(--color-danger)]'"
                class="rounded-lg px-3.5 py-1.5 text-sm font-semibold text-white transition-colors"
                @click="toggleBlock(user)"
            >
                {{ user.blocked ? 'Unblock' : 'Block' }}
            </button>
        </div>

        <MetadataStrip
            :items="[
                { label: 'Email', value: user.email },
                { label: 'Roles', value: roles.map((r) => r.name).join(', ') || 'None' },
                { label: 'Downloaded', value: formatBytes(downloaded), mono: true },
                { label: 'Uploaded', value: formatBytes(uploaded), mono: true },
            ]"
        />

        <SectionHeader title="IP Addresses" accent-line class="mt-5" />

        <DataTable
            :columns="ipColumns"
            :rows="ips"
            clickable
            :row-href="(row) => route('admin.ips.show', row.ip?.id)"
            empty-message="No IP addresses found"
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.ip?.address }}
                </td>
                <td class="px-4 py-2.5">
                    <StatusPill
                        :status="row.ip?.allowed ? 'success' : 'danger'"
                        :label="row.ip?.allowed ? 'Allowed' : 'Denied'"
                    />
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.last_seen_at }}
                </td>
            </template>
        </DataTable>
    </div>
</template>
