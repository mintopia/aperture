import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import StatusPill from '@/Components/UI/StatusPill.vue';

describe('StatusPill', () => {
    it('renders with required props', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        expect(wrapper.text()).toContain('Active');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        expect(wrapper.find('[data-testid="status-pill"]').exists()).toBe(true);
    });

    it('renders a status dot for each status', () => {
        const statuses = ['success', 'danger', 'warning', 'info', 'neutral'];
        statuses.forEach((status) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const dot = wrapper.find('[aria-hidden="true"]');
            expect(dot.exists()).toBe(true);
            expect(dot.classes()).toContain('rounded-full');
            expect(dot.classes()).toContain('h-[7px]');
            expect(dot.classes()).toContain('w-[7px]');
        });
    });

    it('renders label text for each status', () => {
        const cases = [
            { status: 'success', label: 'Active' },
            { status: 'danger', label: 'Failed' },
            { status: 'warning', label: 'Pending' },
            { status: 'info', label: 'Info' },
            { status: 'neutral', label: 'Unknown' },
        ];
        cases.forEach(({ status, label }) => {
            const wrapper = mount(StatusPill, {
                props: { status, label },
            });
            expect(wrapper.text()).toContain(label);
        });
    });

    it('applies correct CSS classes for each status', () => {
        const statuses = ['success', 'danger', 'warning', 'info', 'neutral'];
        statuses.forEach((status) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const pill = wrapper.find('[data-testid="status-pill"]');
            expect(pill.classes()).toContain('inline-flex');
            expect(pill.classes()).toContain('rounded-full');
            expect(pill.classes()).toContain('text-[11px]');
            expect(pill.classes()).toContain('font-semibold');
        });
    });

    it('uses semantic CSS variables for status colors', () => {
        const colorMap = {
            success: '--color-success',
            danger: '--color-danger',
            warning: '--color-warning',
            info: '--color-info',
            neutral: '--color-text-muted',
        };
        Object.entries(colorMap).forEach(([status, cssVar]) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const pill = wrapper.find('[data-testid="status-pill"]');
            const classes = pill.classes().join(' ');
            expect(classes).toContain(`bg-[var(${cssVar})]/14`);
            expect(classes).toContain(`text-[var(${cssVar})]`);
            expect(classes).toContain(`border-[var(${cssVar})]/14`);
        });
    });

    it('applies glow shadow to non-neutral status dots', () => {
        const glowStatuses = ['success', 'danger', 'warning', 'info'];
        glowStatuses.forEach((status) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const dot = wrapper.find('[aria-hidden="true"]');
            const classes = dot.classes().join(' ');
            expect(classes).toContain('shadow-[0_0_6px');
        });
    });

    it('does not apply glow shadow to neutral status dot', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'neutral', label: 'Unknown' },
        });
        const dot = wrapper.find('[aria-hidden="true"]');
        const classes = dot.classes().join(' ');
        expect(classes).not.toContain('shadow-[0_0_6px');
    });

    it('hides dot from assistive technology with aria-hidden', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        const dot = wrapper.find('[aria-hidden="true"]');
        expect(dot.exists()).toBe(true);
    });

    it('updates dot classes when status prop changes', async () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });

        let dot = wrapper.find('[aria-hidden="true"]');
        expect(dot.classes().join(' ')).toContain('bg-[var(--color-success)]');

        await wrapper.setProps({ status: 'danger' });
        dot = wrapper.find('[aria-hidden="true"]');
        expect(dot.classes().join(' ')).toContain('bg-[var(--color-danger)]');
    });
});
