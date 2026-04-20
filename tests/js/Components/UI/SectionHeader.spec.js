import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

describe('SectionHeader', () => {
    it('renders with required title prop', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Test Title' },
        });
        expect(wrapper.exists()).toBe(true);
    });

    it('has data-testid="section-header"', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Test Title' },
        });
        expect(wrapper.find('[data-testid="section-header"]').exists()).toBe(true);
    });

    it('displays title text in h2', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'My Section' },
        });
        expect(wrapper.find('h2').text()).toBe('My Section');
    });

    it('applies uppercase tracking and secondary typography to the title', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Title' },
        });
        const h2 = wrapper.find('h2');
        expect(h2.classes()).toContain('uppercase');
        expect(h2.classes()).toContain('tracking-[0.04em]');
        expect(h2.classes()).toContain('text-[14px]');
    });

    it('applies font-variation-settings inline style for optical sizing', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Title' },
        });
        const h2 = wrapper.find('h2');
        expect(h2.attributes('style')).toContain("font-variation-settings: 'opsz' 16");
    });

    it('renders only one child element (no accent line)', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Title' },
        });
        const children = wrapper.find('[data-testid="section-header"]').element.children;
        expect(children.length).toBe(1);
    });

    it('renders actions slot content', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Title' },
            slots: {
                actions: '<button data-testid="action-btn">Action</button>',
            },
        });
        expect(wrapper.find('[data-testid="action-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="action-btn"]').text()).toBe('Action');
    });

    it('does not render button content when actions slot is empty', () => {
        const wrapper = mount(SectionHeader, {
            props: { title: 'Title' },
        });
        expect(wrapper.find('button').exists()).toBe(false);
    });
});
