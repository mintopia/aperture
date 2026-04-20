<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import { formatRelative } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ip: { type: Object, default: () => ({}) },
    port: { type: Object, default: () => ({}) },
    status: { type: String, default: '' },
    config: { type: String, default: '' },
    shutdown: Boolean,
    users: { type: Array, default: () => [] },
});

const showAccessModal = ref(false);
const togglingAccess = ref(false);

function toggleInternet() {
    showAccessModal.value = true;
}

function confirmToggleInternet() {
    togglingAccess.value = true;
    router.post(
        route('admin.ips.internet', props.ip.id),
        {
            allow: props.ip.allowed ? 0 : 1,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingAccess.value = false;
                showAccessModal.value = false;
            },
        },
    );
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
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                {{ ip.address }}
            </h1>
            <button
                :data-testid="ip.allowed ? 'action-revoke' : 'action-grant'"
                :class="
                    ip.allowed
                        ? 'border-[var(--color-danger)] bg-[var(--color-danger)]'
                        : 'border-[var(--color-success)] bg-[var(--color-success)]'
                "
                class="rounded-md border px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)]"
                @click="toggleInternet"
            >
                {{ ip.allowed ? 'Revoke Access' : 'Grant Access' }}
            </button>
        </div>

        <ConfirmModal
            :show="showAccessModal"
            :title="ip.allowed ? 'Revoke Access?' : 'Grant Access?'"
            :message="
                ip.allowed
                    ? 'This will deny internet access for this IP address.'
                    : 'This will restore internet access for this IP address.'
            "
            :confirm-label="ip.allowed ? 'Revoke Access' : 'Grant Access'"
            :variant="ip.allowed ? 'danger' : 'primary'"
            :loading="togglingAccess"
            @confirm="confirmToggleInternet"
            @cancel="showAccessModal = false"
        >
            <p v-if="users && users.length" class="mt-2 text-[13px] text-[var(--color-text-secondary)]">
                This IP has {{ users.length }} associated user(s).
            </p>
        </ConfirmModal>

        <MetadataStrip
            :items="[
                { label: 'Status', value: ip.allowed ? 'Allowed' : 'Denied' },
                { label: 'Comment', value: ip.comment || '—' },
            ]"
        />

        <SectionHeader title="Associated Users" class="mt-5" />

        <DataTable
            :columns="userColumns"
            :rows="users ?? []"
            clickable
            :row-href="(row) => route('admin.users.show', row.user?.id)"
            empty-message="No associated users"
        >
            <template #row="{ row }">
                <td class="font-mono text-[13px] text-[var(--color-primary)]">
                    {{ row.user?.nickname }}
                </td>
                <td class="text-[13px] text-[var(--color-text-secondary)]">
                    {{ formatRelative(row.last_seen_at) }}
                </td>
            </template>
        </DataTable>

        <template v-if="port">
            <SectionHeader title="Switch Port" class="mt-5" />
            <ConfigBlock v-if="status" :code="status" />
        </template>
    </div>
</template>
