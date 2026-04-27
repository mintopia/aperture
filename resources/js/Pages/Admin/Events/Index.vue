<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelativeTime } from '@/utils/dates';
import { useAdminChannel } from '@/composables/useAdminChannel';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    events: { type: Object, default: () => ({}) },
    totalCount: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
});

const searchInput = ref(props.filters?.search ?? '');
let debounceTimer = null;

const rows = computed(() => props.events?.data ?? []);

const columns = [
    { key: 'created_at', label: 'Time' },
    { key: 'type', label: 'Event' },
    { key: 'message', label: 'Details' },
];

const liveEvents = ref([]);

function addLiveEvent(eventData) {
    const search = searchInput.value.toLowerCase().trim();
    const type = eventData.type ?? '';
    const message = eventData.message ?? '';

    if (search && !type.toLowerCase().includes(search) && !message.toLowerCase().includes(search)) {
        return;
    }

    liveEvents.value.push(eventData);
}

function reloadPage() {
    liveEvents.value = [];
    router.reload({ preserveScroll: true });
}

function onSearchInput(event) {
    searchInput.value = event.target.value;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        liveEvents.value = [];
        router.get(
            route('admin.events.index'),
            { search: searchInput.value, perPage: props.filters?.perPage ?? 50 },
            { preserveState: true, preserveScroll: true },
        );
    }, 300);
}

function levelColorClass(level) {
    if (level === 'critical') return 'text-[var(--color-danger)] danger';
    if (level === 'warning') return 'text-[var(--color-warning)] warning';
    return 'text-[var(--color-primary)]';
}

function formatCount(n) {
    return n.toLocaleString('en-GB');
}

const EVENT_TYPES = [
    'UserConnected',
    'DeviceDiscovered',
    'PortStateChanged',
    'SwitchSyncCompleted',
    'DhcpPoolThresholdReached',
    'InternetAccessChanged',
    'RateLimitChanged',
    'UserBlocked',
    'DnsFilterChanged',
    'SwitchUnreachable',
    'BandwidthAnomalyDetected',
];

const channelEvents = {};
for (const eventType of EVENT_TYPES) {
    channelEvents[eventType] = (data) => {
        addLiveEvent({ type: eventType, ...data });
    };
}

const { connected } = useAdminChannel({ events: channelEvents });

defineExpose({ addLiveEvent });
</script>

<template>
    <div data-testid="event-feed-layout">
        <!-- Page Header -->
        <header class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Event Feed
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Real-time system activity stream</p>
            </div>
            <div class="flex items-center gap-2 pt-2">
                <span
                    data-testid="live-indicator"
                    class="flex items-center gap-1.5 text-[11px] font-semibold"
                    :class="connected ? 'text-green-500' : 'text-[var(--color-text-muted)]'"
                >
                    <span
                        class="inline-block h-2 w-2 rounded-full"
                        :class="connected ? 'animate-pulse bg-green-500' : 'bg-[var(--color-text-muted)]'"
                    />
                    {{ connected ? 'Live' : 'Disconnected' }}
                </span>
            </div>
        </header>

        <SectionHeader title="Events" class="mt-6" />

        <!-- Search & Count Bar -->
        <div class="mb-3 flex items-center justify-between gap-4">
            <div class="relative max-w-xs flex-1">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-[var(--color-text-muted)]"
                    aria-hidden="true"
                >
                    <path
                        fill-rule="evenodd"
                        d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z"
                        clip-rule="evenodd"
                    />
                </svg>
                <input
                    data-testid="event-search"
                    type="text"
                    placeholder="Search events..."
                    :value="searchInput"
                    class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] py-2 pr-3 pl-9 text-[13px] text-[var(--color-text)] placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)] focus:outline-none"
                    @input="onSearchInput"
                />
            </div>
            <span data-testid="event-count" class="text-[11px] text-[var(--color-text-muted)]">
                Showing {{ formatCount(events.total ?? 0) }} of {{ formatCount(totalCount) }} events
            </span>
        </div>

        <!-- Live Events Banner -->
        <div
            v-if="liveEvents.length > 0"
            data-testid="new-events-banner"
            class="mb-3 flex items-center justify-between rounded-md border border-[var(--color-primary)]/20 bg-[var(--color-primary)]/[0.06] px-4 py-2"
        >
            <span class="text-[13px] font-medium text-[var(--color-primary)]">
                {{ liveEvents.length }} new {{ liveEvents.length === 1 ? 'event' : 'events' }} received
            </span>
            <button
                data-testid="new-events-reload"
                type="button"
                class="rounded-md px-3 py-1 text-[12px] font-semibold text-[var(--color-primary)] transition-colors hover:bg-[var(--color-primary)]/[0.1]"
                @click="reloadPage"
            >
                Refresh
            </button>
        </div>

        <!-- Data Table -->
        <section data-testid="event-table-section">
            <template v-if="rows.length === 0">
                <div data-testid="event-empty">
                    <EmptyState
                        title="No events recorded"
                        description="Events will appear here as system activity occurs."
                    />
                </div>
            </template>
            <DataTable v-else :columns="columns" :rows="rows" empty-message="No events found.">
                <template #row="{ row }">
                    <td
                        data-testid="event-timestamp"
                        class="font-mono text-[13px] whitespace-nowrap text-[var(--color-text-secondary)]"
                    >
                        {{ formatRelativeTime(row.created_at) }}
                    </td>
                    <td data-testid="event-type" :class="levelColorClass(row.level)" class="font-mono text-[13px]">
                        {{ row.type }}
                    </td>
                    <td data-testid="event-message" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.message }}
                    </td>
                </template>
            </DataTable>
        </section>

        <Pagination :paginator="events" class="mt-4" />
    </div>
</template>
