<script setup>
import { computed } from 'vue';

const props = defineProps({
    ports: { type: Array, default: () => [] },
    switchId: { type: [Number, String], required: true },
});

const useDualRow = computed(() => props.ports.length >= 16);

const oddPorts = computed(() => props.ports.filter((_, i) => i % 2 === 0));
const evenPorts = computed(() => props.ports.filter((_, i) => i % 2 === 1));

const firstLabel = computed(() => props.ports[0]?.interface ?? '');
const lastLabel = computed(() => props.ports[props.ports.length - 1]?.interface ?? '');

function portColor(port) {
    if (port.status === 'err-disabled') return '#ef4444';
    if (port.admin_status === 'down') return '#6b7280';
    if (['notconnect', 'down'].includes(port.status)) return '#1f2937';

    const speed = parseSpeed(port.speed);
    if (speed >= 1000) return '#22c55e';
    if (speed >= 100) return '#eab308';
    if (speed >= 10) return '#f97316';

    return '#22c55e';
}

function parseSpeed(raw) {
    if (!raw || raw === 'auto') return 1000;

    const autoMatch = raw.match(/^a-(\d+)$/);
    if (autoMatch) return Number(autoMatch[1]);

    const gbpsMatch = raw.match(/^(\d+)G$/);
    if (gbpsMatch) return Number(gbpsMatch[1]) * 1000;

    if (/^\d+$/.test(raw)) return Number(raw);

    return 1000;
}

function portTooltip(port) {
    if (port.description) {
        return `${port.interface} — ${port.description}`;
    }
    return port.interface;
}

function portHref(port) {
    return route('admin.switches.ports.show', {
        switchConfig: props.switchId,
        portId: port.interface,
    });
}

function portCell(port) {
    return {
        key: port.id,
        testId: `port-cell-${port.interface}`,
        href: portHref(port),
        title: portTooltip(port),
        color: portColor(port),
    };
}

const legendItems = [
    { label: '1Gbps+', color: '#22c55e' },
    { label: '100Mbps', color: '#eab308' },
    { label: '10Mbps', color: '#f97316' },
    { label: 'Error', color: '#ef4444' },
    { label: 'Admin Down', color: '#6b7280' },
    { label: 'Not Connected', color: '#1f2937' },
];
</script>

<template>
    <div data-testid="switch-port-grid">
        <!-- Dual-row layout: odd ports top, even ports bottom -->
        <template v-if="useDualRow">
            <div class="inline-flex flex-col gap-1" data-testid="port-grid-dual">
                <div class="flex gap-1">
                    <a
                        v-for="port in oddPorts"
                        :key="port.id"
                        :data-testid="`port-cell-${port.interface}`"
                        :href="portHref(port)"
                        :title="portTooltip(port)"
                        :style="{ backgroundColor: portColor(port) }"
                        class="block h-6 w-6 rounded-sm transition-transform duration-100 hover:scale-125 hover:ring-2 hover:ring-[var(--color-text)]/30 focus:scale-125 focus:ring-2 focus:ring-[var(--color-primary)] focus:outline-none"
                    />
                </div>
                <div class="flex gap-1">
                    <a
                        v-for="port in evenPorts"
                        :key="port.id"
                        :data-testid="`port-cell-${port.interface}`"
                        :href="portHref(port)"
                        :title="portTooltip(port)"
                        :style="{ backgroundColor: portColor(port) }"
                        class="block h-6 w-6 rounded-sm transition-transform duration-100 hover:scale-125 hover:ring-2 hover:ring-[var(--color-text)]/30 focus:scale-125 focus:ring-2 focus:ring-[var(--color-primary)] focus:outline-none"
                    />
                </div>
            </div>
            <div class="mt-1 flex justify-between" :style="{ width: oddPorts.length * 28 - 4 + 'px' }">
                <span class="font-mono text-[10px] text-[var(--color-text-muted)]">{{ firstLabel }}</span>
                <span class="font-mono text-[10px] text-[var(--color-text-muted)]">{{ lastLabel }}</span>
            </div>
        </template>

        <!-- Single-row layout: all ports horizontal -->
        <template v-else>
            <div class="inline-flex gap-1.5">
                <a
                    v-for="port in ports"
                    :key="port.id"
                    :data-testid="`port-cell-${port.interface}`"
                    :href="portHref(port)"
                    :title="portTooltip(port)"
                    :style="{ backgroundColor: portColor(port) }"
                    class="block h-6 w-6 rounded-sm transition-transform duration-100 hover:scale-125 hover:ring-2 hover:ring-[var(--color-text)]/30 focus:scale-125 focus:ring-2 focus:ring-[var(--color-primary)] focus:outline-none"
                />
            </div>
            <div v-if="ports.length > 1" class="mt-1 flex justify-between" :style="{ width: ports.length * 28 - 4 + 'px' }">
                <span class="font-mono text-[10px] text-[var(--color-text-muted)]">{{ firstLabel }}</span>
                <span class="font-mono text-[10px] text-[var(--color-text-muted)]">{{ lastLabel }}</span>
            </div>
        </template>

        <!-- Legend -->
        <div data-testid="port-grid-legend" class="mt-4 flex flex-wrap items-center gap-3">
            <div v-for="item in legendItems" :key="item.label" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-3 w-3 rounded-sm" :style="{ backgroundColor: item.color }" />
                <span class="text-[11px] text-[var(--color-text-muted)]">{{ item.label }}</span>
            </div>
        </div>
    </div>
</template>
