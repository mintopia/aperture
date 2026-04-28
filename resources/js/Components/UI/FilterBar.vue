<script setup>
import { computed } from 'vue';

const props = defineProps({
    search: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Search…' },
    filters: { type: Array, default: () => [] },
    filterValues: { type: Object, default: () => ({}) },
    totalCount: { type: Number, default: 0 },
    filteredCount: { type: Number, default: 0 },
    debounce: { type: Number, default: 300 },
});

const emit = defineEmits(['update:search', 'update:filterValues']);

let debounceTimer = null;

function handleSearchInput(event) {
    const value = event.target.value;
    if (props.debounce === 0) {
        emit('update:search', value);
        return;
    }
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        emit('update:search', value);
    }, props.debounce);
}

const activeFilters = computed(() =>
    props.filters.filter((f) => {
        const val = props.filterValues[f.key];
        return val !== undefined && val !== null && val !== '';
    }),
);

function updateFilter(key, value) {
    const updated = { ...props.filterValues, [key]: value || '' };
    emit('update:filterValues', updated);
}

function removeFilter(key) {
    const updated = { ...props.filterValues, [key]: '' };
    emit('update:filterValues', updated);
}

function filterDisplayLabel(filter) {
    const val = props.filterValues[filter.key];
    const option = filter.options.find((o) => o.value === val);
    return `${filter.label}: ${option?.label ?? val}`;
}
</script>

<template>
    <div data-testid="filter-bar" class="mb-5 flex flex-wrap items-center gap-2.5">
        <div data-testid="filter-search" class="relative max-w-[320px] min-w-[200px] flex-1">
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
                type="text"
                :value="search"
                :placeholder="searchPlaceholder"
                aria-label="Search"
                data-testid="filter-search-input"
                class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] py-2 pr-3 pl-8 text-[13px] text-[var(--color-text)] transition-[border-color] duration-150 outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                @input="handleSearchInput"
            />
        </div>

        <select
            v-for="filter in filters"
            :key="filter.key"
            :data-testid="`filter-select-${filter.key}`"
            :aria-label="filter.label"
            :value="filterValues[filter.key] || ''"
            class="cursor-pointer appearance-none rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] bg-[url('data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2210%22%20height%3D%226%22%20viewBox%3D%220%200%2010%206%22%3E%3Cpath%20fill%3D%22%236b6b6b%22%20d%3D%22M0%200l5%206%205-6z%22%2F%3E%3C%2Fsvg%3E')] bg-[position:right_10px_center] bg-no-repeat py-2 pr-7 pl-2.5 text-xs font-semibold text-[var(--color-text-secondary)] transition-[border-color] duration-150 outline-none focus:border-[var(--color-primary)]"
            @change="updateFilter(filter.key, $event.target.value)"
        >
            <option value="">{{ filter.allLabel || `All ${filter.label}` }}</option>
            <option v-for="opt in filter.options" :key="opt.value" :value="opt.value">
                {{ opt.label }}
            </option>
        </select>

        <span
            v-for="af in activeFilters"
            :key="af.key"
            :data-testid="`filter-pill-${af.key}`"
            class="inline-flex cursor-default items-center gap-1 rounded bg-[var(--color-primary)]/[0.14] py-1 pr-2 pl-2.5 text-[11px] font-semibold text-[var(--color-primary)]"
        >
            {{ filterDisplayLabel(af) }}
            <button
                type="button"
                :data-testid="`filter-pill-remove-${af.key}`"
                :title="`Remove ${af.label} filter`"
                class="inline-flex h-3.5 w-3.5 cursor-pointer items-center justify-center rounded-full border-none bg-transparent text-xs leading-none text-[var(--color-primary)] transition-colors duration-100 hover:bg-[var(--color-primary)]/25"
                @click="removeFilter(af.key)"
            >
                &times;
            </button>
        </span>

        <span
            v-if="totalCount > 0"
            data-testid="filter-count"
            class="ml-auto font-mono text-[11px] text-[var(--color-text-muted)]"
        >
            {{ filteredCount }} of {{ totalCount }}
        </span>
    </div>
</template>
