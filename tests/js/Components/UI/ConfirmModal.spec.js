import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

describe('ConfirmModal', () => {
    const mountComponent = (props = {}, options = {}) =>
        mount(ConfirmModal, {
            props: {
                show: true,
                title: 'Confirm Action',
                message: 'Are you sure you want to continue?',
                ...props,
            },
            global: {
                stubs: {
                    teleport: true,
                },
            },
            ...options,
        });

    it('renders when show=true', () => {
        const wrapper = mountComponent();

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
    });

    it('does not render when show=false', () => {
        const wrapper = mountComponent({ show: false });

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
    });

    it('displays title and message', () => {
        const wrapper = mountComponent({
            title: 'Delete Switch',
            message: 'This action cannot be undone.',
        });

        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toBe('Delete Switch');
        expect(wrapper.find('[data-testid="confirm-modal-message"]').text()).toContain('This action cannot be undone.');
    });

    it('emits confirm on confirm click', async () => {
        const wrapper = mountComponent();

        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });

    it('emits cancel on cancel click', async () => {
        const wrapper = mountComponent();

        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('shows loading state', () => {
        const wrapper = mountComponent({ loading: true });

        expect(wrapper.find('[data-testid="confirm-modal-confirm"]').attributes('disabled')).toBeDefined();
    });

    it('renders slot content', () => {
        const wrapper = mountComponent(
            {},
            {
                slots: {
                    default: '<div data-testid="modal-slot-content">Extra confirmation details</div>',
                },
            },
        );

        expect(wrapper.find('[data-testid="modal-slot-content"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Extra confirmation details');
    });

    it('applies danger variant styling', () => {
        const wrapper = mountComponent({ variant: 'danger' });

        expect(wrapper.find('[data-testid="confirm-modal-confirm"]').classes()).toContain('bg-[var(--color-danger)]');
    });

    it('applies warning variant styling', () => {
        const wrapper = mountComponent({ variant: 'warning' });

        expect(wrapper.find('[data-testid="confirm-modal-confirm"]').classes()).toContain('bg-[var(--color-warning)]');
    });
});
