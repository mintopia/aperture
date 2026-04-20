import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import FilterBar from '@/Components/UI/FilterBar.vue';

const defaultFilters = [
    {
        key: 'type',
        label: 'Type',
        options: [
            { value: 'cisco', label: 'Cisco IOS' },
            { value: 'aruba', label: 'Aruba' },
        ],
    },
    {
        key: 'status',
        label: 'Status',
        options: [
            { value: 'enabled', label: 'Enabled' },
            { value: 'disabled', label: 'Disabled' },
        ],
    },
];

function mountFilterBar(propsOverride = {}) {
    return mount(FilterBar, {
        props: {
            search: '',
            searchPlaceholder: 'Search items…',
            filters: defaultFilters,
            filterValues: {},
            totalCount: 10,
            filteredCount: 10,
            ...propsOverride,
        },
    });
}

describe('FilterBar', () => {
    it('renders the filter bar container', () => {
        const wrapper = mountFilterBar();
        expect(wrapper.find('[data-testid="filter-bar"]').exists()).toBe(true);
    });

    it('renders the search input with placeholder', () => {
        const wrapper = mountFilterBar();
        const input = wrapper.find('[data-testid="filter-search-input"]');
        expect(input.exists()).toBe(true);
        expect(input.attributes('placeholder')).toBe('Search items…');
    });

    it('emits update:search when typing in search input', async () => {
        const wrapper = mountFilterBar();
        const input = wrapper.find('[data-testid="filter-search-input"]');
        await input.setValue('test query');
        expect(wrapper.emitted('update:search')).toBeTruthy();
        expect(wrapper.emitted('update:search')[0]).toEqual(['test query']);
    });

    it('renders filter select dropdowns for each filter', () => {
        const wrapper = mountFilterBar();
        expect(wrapper.find('[data-testid="filter-select-type"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-select-status"]').exists()).toBe(true);
    });

    it('renders default "All" option in each select', () => {
        const wrapper = mountFilterBar();
        const typeSelect = wrapper.find('[data-testid="filter-select-type"]');
        const options = typeSelect.findAll('option');
        expect(options[0].text()).toBe('All Type');
        expect(options[1].text()).toBe('Cisco IOS');
        expect(options[2].text()).toBe('Aruba');
    });

    it('supports custom allLabel for filter selects', () => {
        const wrapper = mountFilterBar({
            filters: [
                {
                    key: 'type',
                    label: 'Type',
                    allLabel: 'All Types',
                    options: [{ value: 'cisco', label: 'Cisco IOS' }],
                },
            ],
        });
        const typeSelect = wrapper.find('[data-testid="filter-select-type"]');
        expect(typeSelect.findAll('option')[0].text()).toBe('All Types');
    });

    it('emits update:filterValues when selecting a filter', async () => {
        const wrapper = mountFilterBar();
        const typeSelect = wrapper.find('[data-testid="filter-select-type"]');
        await typeSelect.setValue('cisco');
        expect(wrapper.emitted('update:filterValues')).toBeTruthy();
        expect(wrapper.emitted('update:filterValues')[0][0]).toEqual({ type: 'cisco' });
    });

    it('shows filter pills for active filters', () => {
        const wrapper = mountFilterBar({
            filterValues: { type: 'cisco' },
        });
        const pill = wrapper.find('[data-testid="filter-pill-type"]');
        expect(pill.exists()).toBe(true);
        expect(pill.text()).toContain('Type: Cisco IOS');
    });

    it('does not show pills for empty filter values', () => {
        const wrapper = mountFilterBar({
            filterValues: { type: '' },
        });
        expect(wrapper.find('[data-testid="filter-pill-type"]').exists()).toBe(false);
    });

    it('emits update:filterValues to clear filter when pill remove clicked', async () => {
        const wrapper = mountFilterBar({
            filterValues: { type: 'cisco', status: 'enabled' },
        });
        const removeBtn = wrapper.find('[data-testid="filter-pill-remove-type"]');
        expect(removeBtn.exists()).toBe(true);
        await removeBtn.trigger('click');
        expect(wrapper.emitted('update:filterValues')[0][0]).toEqual({
            type: '',
            status: 'enabled',
        });
    });

    it('displays filter count showing filtered of total', () => {
        const wrapper = mountFilterBar({
            totalCount: 10,
            filteredCount: 5,
        });
        const count = wrapper.find('[data-testid="filter-count"]');
        expect(count.exists()).toBe(true);
        expect(count.text()).toBe('5 of 10');
    });

    it('hides filter count when totalCount is 0', () => {
        const wrapper = mountFilterBar({
            totalCount: 0,
            filteredCount: 0,
        });
        expect(wrapper.find('[data-testid="filter-count"]').exists()).toBe(false);
    });

    it('renders search icon inside search container', () => {
        const wrapper = mountFilterBar();
        const searchContainer = wrapper.find('[data-testid="filter-search"]');
        expect(searchContainer.find('svg').exists()).toBe(true);
    });

    it('renders multiple active filter pills', () => {
        const wrapper = mountFilterBar({
            filterValues: { type: 'cisco', status: 'enabled' },
        });
        expect(wrapper.find('[data-testid="filter-pill-type"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-pill-status"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="filter-pill-type"]').text()).toContain('Type: Cisco IOS');
        expect(wrapper.find('[data-testid="filter-pill-status"]').text()).toContain('Status: Enabled');
    });
});
