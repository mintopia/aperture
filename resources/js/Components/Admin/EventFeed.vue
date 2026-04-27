<script setup>
import { Link } from '@inertiajs/vue3';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelativeTime } from '@/utils/dates';

defineProps({
    events: { type: Array, default: () => [] },
});
</script>

<template>
    <div data-testid="event-feed">
        <div class="flex items-baseline justify-between">
            <SectionHeader title="Event Feed" />
            <Link
                :href="route('admin.events.index')"
                data-testid="event-feed-view-all"
                class="text-[12px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
            >
                View All &rarr;
            </Link>
        </div>

        <div data-testid="event-feed-scroll" class="max-h-[400px] overflow-y-auto">
            <div v-if="events.length === 0" data-testid="event-feed-empty" class="py-6 text-center">
                <p class="text-[11px] text-[var(--color-text-muted)]">No recent events</p>
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
                        {{ formatRelativeTime(event.created_at) }}
                    </span>
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ event.message }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
