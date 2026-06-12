<script setup>
import { Link } from '@inertiajs/vue3';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelativeTime } from '@/utils/dates';

defineProps({
    events: { type: Array, default: () => [] },
});

const SEVERITY_COLOR = {
    info: 'var(--color-text-muted)',
    warning: 'var(--color-warning)',
    critical: 'var(--color-danger)',
};

function severityColor(severity) {
    return SEVERITY_COLOR[severity] ?? SEVERITY_COLOR.info;
}
</script>

<template>
    <div data-testid="recent-activity">
        <div class="flex items-baseline justify-between">
            <SectionHeader title="Recent Activity" />
            <Link
                :href="route('admin.audit-log.index')"
                data-testid="recent-activity-view-all"
                class="text-[12px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
            >
                View All &rarr;
            </Link>
        </div>

        <div data-testid="recent-activity-scroll" class="max-h-[400px] overflow-y-auto">
            <div v-if="events.length === 0" data-testid="recent-activity-empty" class="py-6 text-center">
                <p class="text-[11px] text-[var(--color-text-muted)]">No recent activity</p>
            </div>

            <div v-else class="space-y-0">
                <div
                    v-for="(event, index) in events"
                    :key="event.id"
                    data-testid="recent-activity-entry"
                    class="recent-activity-item flex items-start gap-3 border-b border-[var(--color-border)] px-1 py-2 last:border-b-0"
                    :style="{ '--i': Math.min(index, 7) }"
                >
                    <span
                        data-testid="recent-activity-severity"
                        :data-severity="event.severity"
                        class="mt-[6px] h-[7px] w-[7px] shrink-0 rounded-full"
                        :style="{ backgroundColor: severityColor(event.severity) }"
                    />
                    <span
                        data-testid="recent-activity-timestamp"
                        class="shrink-0 font-mono text-[11px] text-[var(--color-text-muted)]"
                    >
                        {{ formatRelativeTime(event.created_at) }}
                    </span>
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ event.description }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.recent-activity-item {
    animation: recent-activity-slide-in 300ms cubic-bezier(0.16, 1, 0.3, 1) backwards;
    animation-delay: calc(var(--i, 0) * 30ms);
}

@keyframes recent-activity-slide-in {
    from {
        opacity: 0;
        transform: translateX(12px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .recent-activity-item {
        animation: none;
    }
}
</style>
