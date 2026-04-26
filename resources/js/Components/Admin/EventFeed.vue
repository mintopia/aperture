<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

const props = defineProps({
    maxEvents: {
        type: Number,
        default: 50,
    },
});

const events = ref([]);
const scrollContainer = ref(null);

const EVENT_FORMATTERS = {
    UserConnected: (data) => `${data.user_name} connected from ${data.ip_address}`,
    DeviceDiscovered: (data) => {
        const location = data.ip_address ? ` on ${data.ip_address}` : '';

        return `New device ${data.mac_address} discovered${location}`;
    },
    PortStateChanged: (data) => `Port ${data.port_name} changed to ${data.new_status}`,
    SwitchSyncCompleted: (data) => {
        const base = `Switch ${data.hostname} sync completed (${data.ports_updated} ports updated)`;
        const errorCount = data.errors?.length;

        if (errorCount) {
            return `${base} - ${errorCount} errors`;
        }

        return base;
    },
    DhcpPoolThresholdReached: (data) => `DHCP pool ${data.pool} reached ${data.usage}% utilization`,
    InternetAccessChanged: (data) => `Internet access ${data.enabled ? 'enabled' : 'disabled'} for ${data.ip_address}`,
    RateLimitChanged: (data) => `Rate limit changed for ${data.ip_address} from ${data.old_limit} to ${data.new_limit}`,
    UserBlocked: (data) => `${data.user_name} blocked on ${data.ip_address}: ${data.reason}`,
    DnsFilterChanged: (data) => `DNS filter ${data.enabled ? 'enabled' : 'disabled'} for ${data.ip_address}`,
    SwitchUnreachable: (data) => `Switch ${data.hostname} unreachable after ${data.failureCount} failures`,
    BandwidthAnomalyDetected: (data) => {
        const parts = ['Bandwidth anomaly detected'];

        if (data.ip_address) {
            parts.push(`on ${data.ip_address}`);
        }

        return parts.join(' ');
    },
};

function formatTimestamp(date) {
    return date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

function addEvent(type, data) {
    const entry = {
        id: Date.now() + Math.random(),
        type,
        message: EVENT_FORMATTERS[type]?.(data) ?? `${type} event received`,
        timestamp: formatTimestamp(new Date()),
    };

    events.value.unshift(entry);

    if (events.value.length > props.maxEvents) {
        events.value = events.value.slice(0, props.maxEvents);
    }

    nextTick(() => {
        if (scrollContainer.value) {
            scrollContainer.value.scrollTop = 0;
        }
    });
}

let channel = null;

onMounted(() => {
    if (!window.Echo) {
        return;
    }

    channel = window.Echo.private('admin.events');

    for (const eventType of Object.keys(EVENT_FORMATTERS)) {
        channel.listen(eventType, (data) => {
            addEvent(eventType, data);
        });
    }
});

onUnmounted(() => {
    if (window.Echo) {
        window.Echo.leave('admin.events');
    }
});
</script>

<template>
    <div data-testid="event-feed">
        <SectionHeader title="Event Feed" />

        <div ref="scrollContainer" data-testid="event-feed-scroll" class="max-h-[400px] overflow-y-auto">
            <div v-if="events.length === 0" data-testid="event-feed-empty" class="py-6 text-center">
                <p class="text-[11px] text-[var(--color-text-muted)]">Waiting for events</p>
            </div>

            <div v-else class="space-y-0">
                <div
                    v-for="event in events"
                    :key="event.id"
                    data-testid="event-feed-entry"
                    class="flex items-start gap-3 border-b border-[var(--color-border)] px-1 py-2 last:border-b-0"
                >
                    <span
                        data-testid="event-feed-timestamp"
                        class="shrink-0 font-mono text-[11px] text-[var(--color-text-muted)]"
                    >
                        {{ event.timestamp }}
                    </span>
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ event.message }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
