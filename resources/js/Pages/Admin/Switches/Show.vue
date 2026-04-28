<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import { formatRelative, formatDate } from '@/utils/dates';
import { typeLabel, statusLabel, formatSpeed, formatVlan } from '@/utils/switches';
import SwitchPortGrid from '@/Components/Admin/SwitchPortGrid.vue';
import { useAdminChannel } from '@/composables/useAdminChannel';

function statusDotClass(type) {
    const map = {
        success: 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]',
        danger: 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]',
        warning: 'bg-[var(--color-warning)] shadow-[0_0_6px_var(--color-warning)]',
        neutral: 'bg-[var(--color-text-muted)]',
    };
    return map[type] || map.neutral;
}

function statusTextClass(type) {
    const map = {
        success: 'text-[var(--color-success)]',
        danger: 'text-[var(--color-danger)]',
        warning: 'text-[var(--color-warning)]',
        neutral: 'text-[var(--color-text-muted)]',
    };
    return map[type] || map.neutral;
}

function syncStatusType(status) {
    if (status === 'completed') return 'success';
    if (status === 'failed') return 'danger';
    if (status === 'running') return 'warning';
    return 'neutral';
}

function syncStatusLabel(status) {
    const labels = {
        completed: 'Completed',
        failed: 'Failed',
        running: 'Running',
        pending: 'Pending',
    };
    return labels[status] ?? status;
}

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switchConfig: { type: Object, default: () => ({}) },
    ports: { type: Array, default: () => [] },
    latestSync: { type: Object, default: null },
});

const syncing = ref(false);
const testing = ref(false);

const portSearch = ref('');
const portFilter = ref('all');
let debounceTimer = null;
const debouncedSearch = ref('');

function onSearchInput(event) {
    portSearch.value = event.target.value;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        debouncedSearch.value = portSearch.value;
    }, 300);
}

const portFilterCounts = computed(() => {
    const ports = props.ports;
    return {
        all: ports.length,
        up: ports.filter((p) => ['connected', 'up'].includes(p.status)).length,
        down: ports.filter((p) => ['down', 'notconnect'].includes(p.status)).length,
        errors: ports.filter((p) => p.status === 'err-disabled').length,
    };
});

const filteredPorts = computed(() => {
    let result = props.ports;

    if (portFilter.value === 'up') {
        result = result.filter((p) => ['connected', 'up'].includes(p.status));
    } else if (portFilter.value === 'down') {
        result = result.filter((p) => ['down', 'notconnect'].includes(p.status));
    } else if (portFilter.value === 'errors') {
        result = result.filter((p) => p.status === 'err-disabled');
    }

    const search = debouncedSearch.value.toLowerCase().trim();
    if (search) {
        result = result.filter((p) => {
            const iface = (p.interface || '').toLowerCase();
            const desc = (p.description || '').toLowerCase();
            const status = (p.status || '').toLowerCase();
            const vlan = String(p.vlan ?? '').toLowerCase();
            return iface.includes(search) || desc.includes(search) || status.includes(search) || vlan.includes(search);
        });
    }

    return result;
});

const portColumns = [
    { key: 'interface', label: 'Interface' },
    { key: 'description', label: 'Description' },
    { key: 'status', label: 'Status' },
    { key: 'speed', label: 'Speed' },
    { key: 'vlan', label: 'VLAN' },
    { key: 'poe', label: 'POE' },
];

function statusType(status) {
    if (['up', 'connected'].includes(status)) return 'success';
    if (['down', 'err-disabled'].includes(status)) return 'danger';
    if (['disabled', 'notconnect'].includes(status)) return 'neutral';
    return 'warning';
}

function syncSwitch() {
    syncing.value = true;
    router.post(
        route('admin.switches.sync', props.switchConfig.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = false;
            },
        },
    );
}

const testResult = ref(null);
let testDismissTimer = null;

function clearTestResult() {
    if (testDismissTimer) {
        clearTimeout(testDismissTimer);
        testDismissTimer = null;
    }
    testResult.value = null;
}

async function testConnection() {
    testing.value = true;
    clearTestResult();

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(route('admin.switches.test-connection', props.switchConfig.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
        });
        const data = await response.json();
        testResult.value = { success: data.success, message: data.message };
    } catch {
        testResult.value = { success: false, message: 'Request failed. Please try again.' };
    } finally {
        testing.value = false;
        testDismissTimer = setTimeout(() => {
            testResult.value = null;
        }, 10000);
    }
}

onBeforeUnmount(() => {
    if (testDismissTimer) {
        clearTimeout(testDismissTimer);
    }
});

function refreshSwitchData() {
    router.reload({
        only: ['switchConfig', 'ports', 'latestSync'],
        preserveScroll: true,
    });
}

function onSwitchSyncCompleted(event) {
    if (event.switch_config_id === props.switchConfig.id) {
        refreshSwitchData();
    }
}

function onPortStateChanged() {
    refreshSwitchData();
}

useAdminChannel({
    events: {
        SwitchSyncCompleted: onSwitchSyncCompleted,
        PortStateChanged: onPortStateChanged,
    },
});
</script>

<template>
    <div data-testid="switch-show-layout">
        <!-- Header: title left, actions right -->
        <section data-testid="switch-show-header-card">
            <div class="mb-2 flex items-start justify-between gap-6">
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    :style="{ fontVariationSettings: '\'opsz\' 48' }"
                >
                    {{ switchConfig.name }}
                </h1>
                <div data-testid="switch-show-actions" class="flex items-center gap-2">
                    <button
                        data-testid="action-test"
                        title="Test SSH connectivity to this switch"
                        :disabled="testing"
                        class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)] disabled:opacity-50"
                        @click="testConnection"
                    >
                        {{ testing ? 'Testing…' : 'Test Connection' }}
                    </button>
                    <button
                        data-testid="action-sync"
                        title="Trigger a port sync from the switch"
                        :disabled="syncing"
                        class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)] disabled:opacity-50"
                        @click="syncSwitch"
                    >
                        {{ syncing ? 'Syncing…' : 'Sync Now' }}
                    </button>
                    <Link
                        :href="route('admin.switches.edit', switchConfig.id)"
                        data-testid="action-edit"
                        class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition-colors hover:bg-[var(--color-primary-hover)]"
                    >
                        Edit
                    </Link>
                </div>
            </div>

            <!-- Test Connection Result -->
            <div
                v-if="testResult"
                data-testid="test-result"
                role="status"
                aria-live="polite"
                :class="[
                    'mt-3 inline-flex items-center gap-1.5 rounded border px-3 py-1 text-[11px] font-semibold',
                    testResult.success
                        ? 'border-[var(--color-success)]/20 bg-[var(--color-success)]/10 text-[var(--color-success)]'
                        : 'border-[var(--color-danger)]/20 bg-[var(--color-danger)]/10 text-[var(--color-danger)]',
                ]"
            >
                <span class="font-mono text-[10px] leading-none" aria-hidden="true">{{
                    testResult.success ? '✓' : '✕'
                }}</span>
                {{ testResult.message }}
            </div>
        </section>

        <!-- Metadata Strip: switch details -->
        <section data-testid="switch-details-card">
            <MetadataStrip
                :items="[
                    { label: 'Hostname', value: switchConfig.hostname ?? '—', mono: true },
                    { label: 'Port', value: switchConfig.port ?? '—', mono: true },
                    { label: 'Type', value: typeLabel(switchConfig.type) },
                    { label: 'Status', value: switchConfig.enabled ? 'Enabled' : 'Disabled' },
                    { label: 'Timeout', value: switchConfig.timeout ? switchConfig.timeout + 's' : '—' },
                    {
                        label: 'Last Synced',
                        value: switchConfig.last_synced_at ? formatRelative(switchConfig.last_synced_at) : 'Never',
                    },
                    {
                        label: 'Created',
                        value: switchConfig.created_at ? formatDate(switchConfig.created_at) : '—',
                    },
                ]"
            />
        </section>

        <!-- Sync Status: inline dot, not StatusPill -->
        <section v-if="latestSync" data-testid="sync-status" class="mb-8">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                    >Last Sync</span
                >
                <span class="inline-flex items-center gap-1.5">
                    <span
                        :class="[
                            'inline-block h-[7px] w-[7px] rounded-full',
                            statusDotClass(syncStatusType(latestSync.status)),
                        ]"
                    ></span>
                    <span :class="['text-[13px] font-semibold', statusTextClass(syncStatusType(latestSync.status))]">{{
                        syncStatusLabel(latestSync.status)
                    }}</span>
                </span>
                <span v-if="latestSync.finished_at" class="font-mono text-[11px] text-[var(--color-text-muted)]">
                    {{ formatRelative(latestSync.finished_at) }}
                </span>
                <span v-else-if="latestSync.started_at" class="font-mono text-[11px] text-[var(--color-text-muted)]">
                    Started {{ formatRelative(latestSync.started_at) }}
                </span>
                <span
                    v-if="latestSync.status === 'completed'"
                    class="font-mono text-[11px] text-[var(--color-text-muted)]"
                >
                    · {{ latestSync.ports_created ?? 0 }} created · {{ latestSync.ports_updated ?? 0 }} updated
                </span>
            </div>
            <p
                v-if="latestSync.status === 'failed' && latestSync.error"
                data-testid="sync-error"
                class="mt-1.5 text-[11px] text-[var(--color-danger)]"
            >
                {{ latestSync.error }}
            </p>
        </section>

        <!-- Port Grid Overview -->
        <section v-if="ports.length > 0" data-testid="switch-port-grid-section" class="mb-8">
            <h2
                class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                :style="{ fontVariationSettings: '\'opsz\' 16' }"
            >
                Port Overview
            </h2>
            <SwitchPortGrid :ports="ports" :switch-id="switchConfig.id" />
        </section>

        <section data-testid="switch-ports-card">
            <!-- Ports Section Title -->
            <h2
                class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                :style="{ fontVariationSettings: '\'opsz\' 16' }"
            >
                Ports ({{ ports.length }})
            </h2>

            <!-- Inline search + filter dropdown -->
            <div class="mb-3 flex flex-wrap items-center gap-2.5">
                <div class="relative max-w-[320px] min-w-[200px] flex-1">
                    <label for="port-search" class="sr-only">Search ports</label>
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                        class="absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-[var(--color-text-muted)]"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"
                        />
                    </svg>
                    <input
                        id="port-search"
                        data-testid="port-search"
                        type="text"
                        placeholder="Search ports…"
                        :value="portSearch"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] py-[7px] pr-3 pl-8 text-[13px] text-[var(--color-text)] transition-[border-color] duration-150 outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                        @input="onSearchInput"
                    />
                </div>
                <select
                    data-testid="port-filter-status"
                    :value="portFilter"
                    class="cursor-pointer appearance-none rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] bg-[url('data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2210%22%20height%3D%226%22%20viewBox%3D%220%200%2010%206%22%3E%3Cpath%20fill%3D%22%236b6b6b%22%20d%3D%22M0%200l5%206%205-6z%22%2F%3E%3C%2Fsvg%3E')] bg-[position:right_10px_center] bg-no-repeat py-[7px] pr-7 pl-2.5 text-xs font-semibold text-[var(--color-text-secondary)] transition-[border-color] duration-150 outline-none focus:border-[var(--color-primary)]"
                    @change="portFilter = $event.target.value"
                >
                    <option value="all">All Status</option>
                    <option value="up">Up ({{ portFilterCounts.up }})</option>
                    <option value="down">Down ({{ portFilterCounts.down }})</option>
                    <option value="errors">Errors ({{ portFilterCounts.errors }})</option>
                </select>
                <span
                    v-if="ports.length > 0"
                    data-testid="port-filter-count"
                    class="ml-auto font-mono text-[11px] text-[var(--color-text-muted)]"
                >
                    {{ filteredPorts.length }} of {{ ports.length }}
                </span>
            </div>

            <!-- No Results -->
            <div
                v-if="filteredPorts.length === 0 && ports.length > 0"
                data-testid="port-no-results"
                class="mt-3 py-8 text-center text-[13px] text-[var(--color-text-muted)]"
            >
                No ports match your search
            </div>

            <div class="mt-3">
                <DataTable
                    v-if="filteredPorts.length > 0 || ports.length === 0"
                    :columns="portColumns"
                    :rows="filteredPorts"
                    :row-class="(row) => (row.admin_status === 'down' ? 'bg-[var(--color-warning)]/5' : '')"
                    clickable
                    :row-href="
                        (row) =>
                            route('admin.switches.ports.show', {
                                switchConfig: switchConfig.id,
                                portId: row.interface,
                            })
                    "
                    :row-aria-label="(row) => `Open port ${row.interface}`"
                    empty-message="No ports found. Sync this switch to discover ports."
                >
                    <template #row="{ row }">
                        <td class="py-[10px] font-mono text-[13px] text-[var(--color-text)]">
                            {{ row.interface }}
                        </td>
                        <td class="py-[10px] text-[13px] text-[var(--color-text-secondary)]">
                            {{ row.description || '—' }}
                        </td>
                        <td class="py-[10px]">
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    :class="[
                                        'inline-block h-[7px] w-[7px] rounded-full',
                                        statusDotClass(statusType(row.status)),
                                    ]"
                                ></span>
                                <span :class="['text-[13px] font-semibold', statusTextClass(statusType(row.status))]">{{
                                    statusLabel(row.status)
                                }}</span>
                            </span>
                        </td>
                        <td class="py-[10px] text-[13px] text-[var(--color-text-secondary)]">
                            {{ formatSpeed(row.speed) }}
                        </td>
                        <td class="py-[10px] font-mono text-[13px] text-[var(--color-text-secondary)]">
                            {{ formatVlan(row.vlan ?? null, row.switchport_mode) }}
                        </td>
                        <td class="py-[10px] text-[13px] text-[var(--color-text-secondary)]">
                            {{ row.poe || '—' }}
                        </td>
                    </template>
                </DataTable>
            </div>
        </section>

    </div>
</template>
