import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import StatCard from '@/Components/UI/StatCard.vue';

describe('StatCard', () => {
    it('renders with required props', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42 },
        });
        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('42');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42 },
        });
        expect(wrapper.find('[data-testid="stat-card"]').exists()).toBe(true);
    });

    it('displays the value in stat-value element', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Revenue', value: '$1,234' },
        });
        expect(wrapper.find('[data-testid="stat-value"]').text()).toBe('$1,234');
    });

    it('accepts string value', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Status', value: 'Healthy' },
        });
        expect(wrapper.find('[data-testid="stat-value"]').text()).toBe('Healthy');
    });

    it('applies hero styles when hero is true', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Total', value: 100, hero: true },
        });
        const card = wrapper.find('[data-testid="stat-card"]');
        expect(card.classes().some((c) => c.includes('bg-'))).toBe(true);
        const valueEl = wrapper.find('[data-testid="stat-value"]');
        expect(valueEl.classes()).toContain('text-[40px]');
    });

    it('applies non-hero styles by default', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Count', value: 5 },
        });
        const valueEl = wrapper.find('[data-testid="stat-value"]');
        expect(valueEl.classes()).toContain('text-2xl');
    });

    it('applies color variants', () => {
        const colors = ['text', 'primary', 'accent', 'success', 'danger', 'warning'];
        colors.forEach((color) => {
            const wrapper = mount(StatCard, {
                props: { label: 'Test', value: 1, color },
            });
            expect(wrapper.find('[data-testid="stat-value"]').exists()).toBe(true);
        });
    });

    it('applies accent border style when accentBorder is set', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Test', value: 1, accentBorder: 'primary' },
        });
        const card = wrapper.find('[data-testid="stat-card"]');
        expect(card.classes()).toContain('border-l-[3px]');
        expect(card.attributes('style')).toContain('border-left-color');
    });

    it('does not apply accent border by default', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Test', value: 1 },
        });
        const card = wrapper.find('[data-testid="stat-card"]');
        expect(card.classes()).not.toContain('border-l-[3px]');
    });

    it('renders slot content', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42 },
            slots: { default: '<span class="trend">+5%</span>' },
        });
        expect(wrapper.find('.trend').exists()).toBe(true);
        expect(wrapper.text()).toContain('+5%');
    });

    it('renders an optional label dot', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Online', value: 42, labelDotColor: 'success' },
        });

        expect(wrapper.find('[data-testid="stat-label-dot"]').exists()).toBe(true);
    });
});
