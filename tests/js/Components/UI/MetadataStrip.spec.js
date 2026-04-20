import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';

const items = [
    { label: 'Status', value: 'Active' },
    { label: 'Created', value: '2024-01-01' },
];

describe('MetadataStrip', () => {
    it('renders with required props', () => {
        const wrapper = mount(MetadataStrip, {
            props: { items },
        });
        expect(wrapper.find('[data-testid="metadata-strip"]').exists()).toBe(true);
    });

    it('renders all items', () => {
        const wrapper = mount(MetadataStrip, {
            props: { items },
        });
        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Active');
        expect(wrapper.text()).toContain('Created');
        expect(wrapper.text()).toContain('2024-01-01');
    });

    it('applies mono class when item has mono flag', () => {
        const monoItems = [{ label: 'ID', value: 'abc-123', mono: true }];
        const wrapper = mount(MetadataStrip, {
            props: { items: monoItems },
        });
        const valueSpan = wrapper.findAll('span').find((s) => s.text() === 'abc-123');
        expect(valueSpan.classes()).toContain('font-mono');
        expect(valueSpan.classes()).toContain('text-[14px]');
    });

    it('does not apply mono class when mono is not set', () => {
        const wrapper = mount(MetadataStrip, {
            props: { items: [{ label: 'Name', value: 'Test' }] },
        });
        const valueSpan = wrapper.findAll('span').find((s) => s.text() === 'Test');
        expect(valueSpan.classes()).not.toContain('font-mono');
    });

    it('applies large styles when item has large flag', () => {
        const largeItems = [{ label: 'Total', value: '42', large: true }];
        const wrapper = mount(MetadataStrip, {
            props: { items: largeItems },
        });
        const valueSpan = wrapper.findAll('span').find((s) => s.text() === '42');
        expect(valueSpan.classes()).toContain('text-sm');
        expect(valueSpan.classes()).toContain('font-semibold');
    });

    it('renders empty when items array is empty', () => {
        const wrapper = mount(MetadataStrip, {
            props: { items: [] },
        });
        expect(wrapper.find('[data-testid="metadata-strip"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="metadata-item"]')).toHaveLength(0);
    });

    it('applies flex-none to each item for fixed sizing', () => {
        const wrapper = mount(MetadataStrip, {
            props: { items },
        });
        const itemDivs = wrapper.findAll('[data-testid="metadata-item"]');
        itemDivs.forEach((item) => {
            expect(item.classes()).toContain('flex-none');
        });
    });

    it('applies border-right separator to non-last items only', () => {
        const threeItems = [
            { label: 'A', value: '1' },
            { label: 'B', value: '2' },
            { label: 'C', value: '3' },
        ];
        const wrapper = mount(MetadataStrip, {
            props: { items: threeItems },
        });
        const itemDivs = wrapper.findAll('[data-testid="metadata-item"]');
        expect(itemDivs[0].classes()).toContain('border-r');
        expect(itemDivs[0].classes()).toContain('mr-8');
        expect(itemDivs[0].classes()).toContain('pr-8');
        expect(itemDivs[1].classes()).toContain('border-r');
        expect(itemDivs[2].classes()).not.toContain('border-r');
        expect(itemDivs[2].classes()).not.toContain('mr-8');
        expect(itemDivs[2].classes()).not.toContain('pr-8');
    });

    it('renders scoped slot content for an item', () => {
        const wrapper = mount(MetadataStrip, {
            props: { items: [{ label: 'Status', value: 'Active' }] },
            slots: { Status: '<span class="custom-slot">Custom</span>' },
        });
        expect(wrapper.find('.custom-slot').exists()).toBe(true);
        expect(wrapper.text()).toContain('Custom');
    });
});
