<script setup>
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ip: Object,
    port: Object,
    status: String,
    config: String,
    shutdown: Boolean,
    users: Array,
});

function toggleInternet(ip) {
    router.post(route('admin.ips.internet', ip.id), {
        allow: ip.allowed ? 0 : 1,
    });
}

// eslint-disable-next-line no-unused-vars
function togglePort(ip) {
    router.post(route('admin.ips.port', ip.id), {
        shutdown: props.shutdown ? 0 : 1,
    });
}

const userColumns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'last_seen', label: 'Last Seen' },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h1
                data-testid="page-title"
                class="font-heading font-mono text-xl font-bold text-[var(--color-text)] sm:text-2xl"
            >
                {{ ip.address }}
            </h1>
            <button
                :data-testid="ip.allowed ? 'action-revoke' : 'action-grant'"
                :class="ip.allowed ? 'bg-[var(--color-danger)]' : 'bg-[var(--color-success)]'"
                class="rounded-lg px-3.5 py-1.5 text-sm font-semibold text-white transition-colors"
                @click="toggleInternet(ip)"
            >
                {{ ip.allowed ? 'Revoke Access' : 'Grant Access' }}
            </button>
        </div>

        <MetadataStrip
            :items="[
                { label: 'Status', value: ip.allowed ? 'Allowed' : 'Denied' },
                { label: 'Comment', value: ip.comment || '—' },
            ]"
        />

        <SectionHeader title="Associated Users" accent-line class="mt-5" />

        <DataTable
            :columns="userColumns"
            :rows="users ?? []"
            clickable
            :row-href="(row) => route('admin.users.show', row.user?.id)"
            empty-message="No associated users"
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 text-sm text-[var(--color-primary)]">
                    {{ row.user?.nickname }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.last_seen_at }}
                </td>
            </template>
        </DataTable>

        <template v-if="port">
            <SectionHeader title="Switch Port" accent-line class="mt-5" />
            <ConfigBlock v-if="status" :code="status" />
        </template>
    </div>
</template>
