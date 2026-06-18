<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const links = computed(() => {
    const configured = props.settings?.links;
    return Array.isArray(configured) ? configured : [];
});

const layout = computed(() => props.settings?.layout ?? 'horizontal');
const isVertical = computed(() => layout.value === 'vertical');
</script>

<template>
    <div data-testid="block-link-strip">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ title }}
        </h3>
        <div
            v-if="links.length"
            data-testid="link-strip-list"
            class="flex"
            :class="isVertical ? 'flex-col' : 'items-center'"
        >
            <a
                v-for="(link, index) in links"
                :key="index"
                :href="link.url"
                target="_blank"
                rel="noopener noreferrer"
                :data-testid="'link-strip-item-' + index"
                class="flex flex-1 items-center gap-1.5 text-[13px] font-medium text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                :class="[
                    index < links.length - 1
                        ? isVertical
                            ? 'border-b border-[var(--color-border)] pb-3'
                            : 'border-r border-[var(--color-border)] pr-5'
                        : '',
                ]"
                :style="
                    !isVertical && index > 0
                        ? 'padding-left: 1.25rem'
                        : isVertical && index > 0
                          ? 'padding-top: 0.75rem'
                          : ''
                "
            >
                {{ link.label }}
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 16 16"
                    fill="currentColor"
                    class="h-3 w-3 opacity-50"
                >
                    <path
                        d="M6.22 8.72a.75.75 0 0 0 1.06 1.06l5.22-5.22v1.69a.75.75 0 0 0 1.5 0v-3.5a.75.75 0 0 0-.75-.75h-3.5a.75.75 0 0 0 0 1.5h1.69L6.22 8.72Z"
                    />
                    <path
                        d="M3.5 6.75c0-.69.56-1.25 1.25-1.25H7A.75.75 0 0 0 7 4H4.75A2.75 2.75 0 0 0 2 6.75v4.5A2.75 2.75 0 0 0 4.75 14h4.5A2.75 2.75 0 0 0 12 11.25V9a.75.75 0 0 0-1.5 0v2.25c0 .69-.56 1.25-1.25 1.25h-4.5c-.69 0-1.25-.56-1.25-1.25v-4.5Z"
                    />
                </svg>
            </a>
        </div>
        <p v-else class="text-sm text-[var(--color-text-muted)]">No links available</p>
    </div>
</template>
