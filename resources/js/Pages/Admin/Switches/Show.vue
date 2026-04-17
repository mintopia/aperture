<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import { formatRelative, formatDate } from '@/utils/dates';
import { typeLabel, statusLabel } from '@/utils/switches';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switchConfig: { type: Object, default: () => ({}) },
    ports: { type: Array, default: () => [] },
    canDownloadConfig: { type: Boolean, default: false },
    runningConfig: { type: String, default: '' },
    latestSync: { type: Object, default: null },
});

const showDeleteModal = ref(false);
const deleting = ref(false);
const syncing = ref(false);
const testing = ref(false);
const showConfig = ref(false);

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

function confirmDelete() {
    deleting.value = true;
    router.delete(route('admin.switches.destroy', props.switchConfig.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
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

function syncStatusType(status) {
    if (status === 'completed') return 'success';
    if (status === 'running') return 'warning';
    if (status === 'failed') return 'danger';
    return 'neutral';
}

function syncStatusLabel(status) {
    if (!status) return 'Unknown';
    return status.charAt(0).toUpperCase() + status.slice(1);
}

onBeforeUnmount(() => {
    if (testDismissTimer) {
        clearTimeout(testDismissTimer);
    }
});
</script>

<template>
    <div>
        <!-- Header -->
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                >
                    {{ switchConfig.name }}
                </h1>
                <p class="mt-0.5 font-mono text-sm text-[var(--color-text-secondary)]">
                    {{ switchConfig.hostname }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    data-testid="switch-type-badge"
                    class="inline-flex rounded-md bg-[var(--color-primary)]/10 px-2 py-0.5 text-xs font-semibold text-[var(--color-primary)]"
                >
                    {{ typeLabel(switchConfig.type) }}
                </span>
                <StatusPill
                    data-testid="switch-status"
                    :status="switchConfig.enabled ? 'success' : 'neutral'"
                    :label="switchConfig.enabled ? 'Enabled' : 'Disabled'"
                />
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mb-3 flex flex-wrap gap-2">
            <Link
                :href="route('admin.switches.edit', switchConfig.id)"
                data-testid="action-edit"
                class="rounded-lg border border-[var(--color-border)] px-3.5 py-1.5 text-sm font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
            >
                Edit
            </Link>
            <button
                data-testid="action-sync"
                title="Trigger a port sync from the switch"
                :disabled="syncing"
                class="rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                @click="syncSwitch"
            >
                {{ syncing ? 'Syncing…' : 'Sync Now' }}
            </button>
            <button
                data-testid="action-test"
                title="Test SSH connectivity to this switch"
                :disabled="testing"
                class="rounded-lg border border-[var(--color-border)] px-3.5 py-1.5 text-sm font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:opacity-50"
                @click="testConnection"
            >
                {{ testing ? 'Testing…' : 'Test Connection' }}
            </button>
            <button
                data-testid="action-delete"
                title="Remove this switch and all its data"
                class="rounded-lg bg-[var(--color-danger)] px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-danger)]/80"
                @click="showDeleteModal = true"
            >
                Delete
            </button>
        </div>

        <!-- Test Connection Result -->
        <div
            v-if="testResult"
            data-testid="test-result"
            :class="[
                'mb-3 inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold',
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

        <!-- Sync Status -->
        <div
            v-if="latestSync"
            data-testid="sync-status"
            class="mb-5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3"
        >
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-xs font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >Last Sync</span
                >
                <StatusPill :status="syncStatusType(latestSync.status)" :label="syncStatusLabel(latestSync.status)" />
                <span v-if="latestSync.finished_at" class="text-xs text-[var(--color-text-secondary)]">
                    {{ formatRelative(latestSync.finished_at) }}
                </span>
                <span v-else-if="latestSync.started_at" class="text-xs text-[var(--color-text-secondary)]">
                    Started {{ formatRelative(latestSync.started_at) }}
                </span>
                <span v-if="latestSync.status === 'completed'" class="text-xs text-[var(--color-text-secondary)]">
                    · {{ latestSync.ports_created ?? 0 }} created · {{ latestSync.ports_updated ?? 0 }} updated
                </span>
            </div>
            <p
                v-if="latestSync.status === 'failed' && latestSync.error"
                data-testid="sync-error"
                class="mt-1.5 text-xs text-[var(--color-danger)]"
            >
                {{ latestSync.error }}
            </p>
        </div>
        <div v-else class="mb-5" />

        <!-- Info Strip -->
        <MetadataStrip
            :items="[
                { label: 'Hostname', value: switchConfig.hostname ?? '—', mono: true },
                { label: 'Port', value: switchConfig.port ?? '—', mono: true },
                { label: 'Type', value: typeLabel(switchConfig.type) },
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

        <!-- Ports Table -->
        <SectionHeader title="Ports" accent-line class="mt-5" />

        <!-- Port Search -->
        <div class="mt-3">
            <input
                data-testid="port-search"
                type="text"
                placeholder="Search ports…"
                :value="portSearch"
                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] placeholder-[var(--color-text-muted)] focus:border-[var(--color-primary)] focus:outline-none"
                @input="onSearchInput"
            />
        </div>

        <!-- Port Filter Chips -->
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <button
                v-for="chip in [
                    { key: 'all', label: 'All' },
                    { key: 'up', label: 'Up' },
                    { key: 'down', label: 'Down' },
                    { key: 'errors', label: 'Errors' },
                ]"
                :key="chip.key"
                :data-testid="`port-filter-${chip.key}`"
                :class="
                    portFilter === chip.key
                        ? 'rounded-full border border-[var(--color-primary)]/30 bg-[var(--color-primary)]/10 px-3 py-1 text-xs font-semibold text-[var(--color-primary)]'
                        : 'rounded-full border border-[var(--color-border)] px-3 py-1 text-xs font-semibold text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                "
                @click="portFilter = chip.key"
            >
                {{ chip.label }} ({{ portFilterCounts[chip.key] }})
            </button>

            <span data-testid="port-filter-count" class="ml-auto text-xs text-[var(--color-text-muted)]">
                Showing {{ filteredPorts.length }} of {{ ports.length }} ports
            </span>
        </div>

        <!-- No Results -->
        <div
            v-if="filteredPorts.length === 0 && ports.length > 0"
            data-testid="port-no-results"
            class="mt-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-8 text-center text-sm text-[var(--color-text-muted)]"
        >
            No ports match your search
        </div>

        <DataTable
            v-if="filteredPorts.length > 0 || ports.length === 0"
            :columns="portColumns"
            :rows="filteredPorts"
            :row-class="
                (row) =>
                    row.admin_status === 'down'
                        ? 'opacity-40'
                        : row.status === 'down' || row.status === 'notconnect'
                          ? 'opacity-50'
                          : ''
            "
            clickable
            :row-href="
                (row) => route('admin.switches.ports.show', { switchConfig: switchConfig.id, portId: row.interface })
            "
            empty-message="No ports found. Sync this switch to discover ports."
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.interface }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.description || '—' }}
                </td>
                <td class="px-4 py-2.5">
                    <StatusPill :status="statusType(row.status)" :label="statusLabel(row.status)" />
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.speed || '—' }}
                </td>
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-secondary)]">
                    {{ row.vlan ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.poe || '—' }}
                </td>
            </template>
        </DataTable>

        <!-- Running Config -->
        <div v-if="canDownloadConfig" class="mt-6">
            <SectionHeader title="Running Config">
                <template #actions>
                    <div class="flex gap-2">
                        <button
                            data-testid="action-toggle-config"
                            class="rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                            @click="showConfig = !showConfig"
                        >
                            {{ showConfig ? 'Hide Config' : 'View Running Config' }}
                        </button>
                        <a
                            :href="route('admin.switches.config', switchConfig.id)"
                            data-testid="action-download-config"
                            class="rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                        >
                            Download
                        </a>
                    </div>
                </template>
            </SectionHeader>

            <ConfigBlock v-if="showConfig && runningConfig" :code="runningConfig" />
            <p
                v-else-if="showConfig && !runningConfig"
                class="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-8 text-center text-sm text-[var(--color-text-muted)]"
            >
                No running config available. Sync the switch to retrieve its configuration.
            </p>
        </div>

        <!-- Delete Confirmation Modal -->
        <ConfirmModal
            :show="showDeleteModal"
            title="Delete Switch"
            :message="`Are you sure you want to delete ${switchConfig.name}? This will remove the switch and all associated port data. This action cannot be undone.`"
            confirm-label="Delete Switch"
            variant="danger"
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="showDeleteModal = false"
        />
    </div>
</template>
