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

    it('renders success status with correct icon', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        expect(wrapper.text()).toContain('✓');
        expect(wrapper.text()).toContain('Active');
    });

    it('renders danger status with correct icon', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'danger', label: 'Failed' },
        });
        expect(wrapper.text()).toContain('✕');
        expect(wrapper.text()).toContain('Failed');
    });

    it('renders warning status with correct icon', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'warning', label: 'Pending' },
        });
        expect(wrapper.text()).toContain('▲');
        expect(wrapper.text()).toContain('Pending');
    });

    it('renders info status with correct icon', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'info', label: 'Info' },
        });
        expect(wrapper.text()).toContain('●');
        expect(wrapper.text()).toContain('Info');
    });

    it('renders neutral status with correct icon', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'neutral', label: 'Unknown' },
        });
        expect(wrapper.text()).toContain('—');
        expect(wrapper.text()).toContain('Unknown');
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
        });
    });

    it('hides icon from assistive technology with aria-hidden', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'success', label: 'Active' },
        });
        const iconSpan = wrapper.find('[aria-hidden="true"]');
        expect(iconSpan.exists()).toBe(true);
    });
});
