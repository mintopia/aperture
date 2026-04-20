<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import { formatBytes } from '@/helpers.js';
import { formatRelative } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    ips: { type: Array, default: () => [] },
    auths: { type: Array, default: () => [] },
    downloaded: { type: Number, default: 0 },
    uploaded: { type: Number, default: 0 },
});

const showBlockModal = ref(false);
const blocking = ref(false);

function toggleBlock() {
    showBlockModal.value = true;
}

function confirmBlock() {
    blocking.value = true;
    router.post(
        route('admin.users.block', props.user.id),
        {
            block: props.user.blocked ? 0 : 1,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                blocking.value = false;
                showBlockModal.value = false;
            },
        },
    );
}

const ipColumns = [
    { key: 'address', label: 'Address' },
    { key: 'status', label: 'Status' },
    { key: 'last_seen', label: 'Last Seen' },
];
</script>

<template>
    <div data-testid="user-show-layout" class="space-y-6">
        <!-- Header -->
        <header class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    {{ user.nickname }}
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">User account details and IP history.</p>
            </div>
            <div class="flex items-center gap-2">
                <a
                    :href="route('admin.users.edit', user.id)"
                    data-testid="action-edit"
                    class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                >
                    Edit
                </a>
                <button
                    :data-testid="user.blocked ? 'action-unblock' : 'action-block'"
                    :class="
                        user.blocked
                            ? 'rounded-md border border-[var(--color-success)] bg-[var(--color-success)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition-colors hover:opacity-90'
                            : 'rounded-md border border-[var(--color-danger)] bg-[var(--color-danger)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition-colors hover:opacity-90'
                    "
                    @click="toggleBlock"
                >
                    {{ user.blocked ? 'Unblock' : 'Block' }}
                </button>
            </div>
        </header>

        <ConfirmModal
            :show="showBlockModal"
            :title="user.blocked ? 'Unblock User?' : 'Block User?'"
            :message="
                user.blocked
                    ? 'This will restore internet access for this user and their associated IPs.'
                    : 'This will deny internet access for this user and their associated IPs.'
            "
            :confirm-label="user.blocked ? 'Unblock' : 'Block'"
            :variant="user.blocked ? 'primary' : 'danger'"
            :loading="blocking"
            @confirm="confirmBlock"
            @cancel="showBlockModal = false"
        >
            <p class="mt-2 text-[13px] text-[var(--color-text-secondary)]">
                This user has {{ ips.length }} associated IP(s).
            </p>
        </ConfirmModal>

        <MetadataStrip
            :items="[
                { label: 'Email', value: user.email },
                { label: 'Roles', value: roles.map((r) => r.name).join(', ') || 'None' },
                { label: 'Downloaded', value: formatBytes(downloaded), mono: true },
                { label: 'Uploaded', value: formatBytes(uploaded), mono: true },
            ]"
        />

        <section data-testid="user-ips-section">
            <SectionHeader title="IP Addresses" />

            <DataTable
                :columns="ipColumns"
                :rows="ips"
                clickable
                :row-href="(row) => route('admin.ips.show', row.ip?.address)"
                :row-aria-label="(row) => `Open IP ${row.ip?.address}`"
                empty-message="No IP addresses found."
            >
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-text)]">
                        {{ row.ip?.address }}
                    </td>
                    <td>
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                :class="[
                                    'inline-block h-[7px] w-[7px] rounded-full',
                                    row.ip?.allowed
                                        ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                        : 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]',
                                ]"
                            />
                            <span
                                :class="[
                                    'text-xs font-semibold',
                                    row.ip?.allowed ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]',
                                ]"
                            >
                                {{ row.ip?.allowed ? 'Allowed' : 'Denied' }}
                            </span>
                        </span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.last_seen_at) }}
                    </td>
                </template>
            </DataTable>
        </section>
    </div>
</template>
