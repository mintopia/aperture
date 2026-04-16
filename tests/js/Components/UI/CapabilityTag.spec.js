import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CapabilityTag from '@/Components/UI/CapabilityTag.vue';

describe('CapabilityTag', () => {
    it('renders capability name formatted', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dns-filtering', active: true },
        });
        expect(wrapper.text()).toContain('Dns Filtering');
    });

    it('applies active styling when active', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp', active: true },
        });
        expect(wrapper.classes()).not.toContain('opacity-40');
    });

    it('applies inactive styling when not active', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp', active: false },
        });
        expect(wrapper.classes()).toContain('opacity-40');
    });

    it('has correct data-testid', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'captive-portal', active: true },
        });
        expect(wrapper.attributes('data-testid')).toBe('capability-tag-captive-portal');
    });

    it('defaults active to false', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp' },
        });
        expect(wrapper.classes()).toContain('opacity-40');
    });
});
