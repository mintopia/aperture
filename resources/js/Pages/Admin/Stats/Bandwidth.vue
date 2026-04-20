<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import { formatBytesComponents } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

defineProps({
    topTalkers: { type: Array, default: () => [] },
});

const columns = [
    { key: 'ip', label: 'IP Address' },
    { key: 'user', label: 'User' },
    { key: 'downloaded', label: 'Downloaded' },
    { key: 'uploaded', label: 'Uploaded' },
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
                Bandwidth &amp; Top Talkers
            </h1>
        </div>

        <div class="mt-6">
            <DataTable :columns="columns" :rows="topTalkers ?? []" empty-message="No bandwidth data available">
                <template #row="{ row }">
                    <td class="py-2.5 font-mono text-[13px] text-[var(--color-text)]">
                        {{ row.ip }}
                    </td>
                    <td class="py-2.5 text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.nickname ?? '—' }}
                    </td>
                    <td class="py-2.5 text-[13px]">
                        <span
                            class="font-heading text-[22px] font-bold tracking-[-0.02em] text-[var(--color-success)]"
                            :style="{ fontVariationSettings: '\'opsz\' 32' }"
                        >
                            {{ formatBytesComponents(row.received).value }}
                        </span>
                        <span class="ml-1 text-[11px] font-semibold text-[var(--color-text-muted)]">
                            {{ formatBytesComponents(row.received).unit }}
                        </span>
                    </td>
                    <td class="py-2.5 text-[13px]">
                        <span
                            class="font-heading text-[22px] font-bold tracking-[-0.02em] text-[var(--color-info)]"
                            :style="{ fontVariationSettings: '\'opsz\' 32' }"
                        >
                            {{ formatBytesComponents(row.sent).value }}
                        </span>
                        <span class="ml-1 text-[11px] font-semibold text-[var(--color-text-muted)]">
                            {{ formatBytesComponents(row.sent).unit }}
                        </span>
                    </td>
                </template>
            </DataTable>
        </div>
    </div>
</template>
