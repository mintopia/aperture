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

    it('uses font-heading text-[28px] for all values', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Count', value: 5 },
        });
        const valueEl = wrapper.find('[data-testid="stat-value"]');
        expect(valueEl.classes()).toContain('font-heading');
        expect(valueEl.classes()).toContain('text-[28px]');
    });

    it('applies tracking-[-0.02em] to value', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Count', value: 5 },
        });
        const valueEl = wrapper.find('[data-testid="stat-value"]');
        expect(valueEl.classes()).toContain('tracking-[-0.02em]');
    });

    it('applies font-variation-settings on value', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Count', value: 5 },
        });
        const valueEl = wrapper.find('[data-testid="stat-value"]');
        expect(valueEl.attributes('style')).toContain("font-variation-settings: 'opsz' 36");
    });

    it('has no card container styling (no border, no bg, no rounded)', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Test', value: 1 },
        });
        const card = wrapper.find('[data-testid="stat-card"]');
        expect(card.classes()).not.toContain('border');
        expect(card.classes()).not.toContain('rounded-xl');
        expect(card.classes()).not.toContain('shadow');
    });

    it('applies label styling matching mockup', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Test', value: 1 },
        });
        const labelEl = wrapper.find('[data-testid="stat-card"] p:first-child');
        expect(labelEl.classes()).toContain('text-[10px]');
        expect(labelEl.classes()).toContain('font-semibold');
        expect(labelEl.classes()).toContain('tracking-[0.06em]');
        expect(labelEl.classes()).toContain('uppercase');
        expect(labelEl.classes()).toContain('mb-[3px]');
        expect(labelEl.classes()).toContain('text-[var(--color-text-muted)]');
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

    it('does not render label dot when labelDotColor is not provided', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42 },
        });

        expect(wrapper.find('[data-testid="stat-label-dot"]').exists()).toBe(false);
    });

    it('renders sub text when sub prop is provided', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42, sub: '12 online' },
        });

        const subEl = wrapper.find('[data-testid="stat-sub"]');
        expect(subEl.exists()).toBe(true);
        expect(subEl.text()).toBe('12 online');
    });

    it('applies sub text styling matching mockup', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42, sub: '12 online' },
        });

        const subEl = wrapper.find('[data-testid="stat-sub"]');
        expect(subEl.classes()).toContain('text-[11px]');
        expect(subEl.classes()).toContain('text-[var(--color-text-muted)]');
        expect(subEl.classes()).toContain('mt-[2px]');
        expect(subEl.classes()).toContain('font-mono');
    });

    it('does not render sub text when sub prop is empty', () => {
        const wrapper = mount(StatCard, {
            props: { label: 'Users', value: 42 },
        });

        expect(wrapper.find('[data-testid="stat-sub"]').exists()).toBe(false);
    });
});
