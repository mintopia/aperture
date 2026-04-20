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
        const btn = wrapper.find('[data-testid="confirm-modal-confirm"]');

        expect(btn.classes()).toContain('text-[var(--color-danger)]');
        expect(btn.classes()).toContain('border-[var(--color-danger)]/40');
    });

    it('applies warning variant styling', () => {
        const wrapper = mountComponent({ variant: 'warning' });
        const btn = wrapper.find('[data-testid="confirm-modal-confirm"]');

        expect(btn.classes()).toContain('text-[var(--color-warning)]');
        expect(btn.classes()).toContain('border-[var(--color-warning)]/40');
    });

    it('applies primary variant styling', () => {
        const wrapper = mountComponent({ variant: 'primary' });
        const btn = wrapper.find('[data-testid="confirm-modal-confirm"]');

        expect(btn.classes()).toContain('bg-[var(--color-primary)]');
        expect(btn.classes()).toContain('text-white');
    });

    it('uses Dispatch modal surface styling', () => {
        const wrapper = mountComponent();
        const dialog = wrapper.find('[role="dialog"]');

        expect(dialog.classes()).toContain('rounded');
        expect(dialog.classes()).toContain('border');
        expect(dialog.classes()).toContain('border-[var(--color-border)]');
    });

    it('uses Dispatch button sizing', () => {
        const wrapper = mountComponent();
        const cancelBtn = wrapper.find('[data-testid="confirm-modal-cancel"]');
        const confirmBtn = wrapper.find('[data-testid="confirm-modal-confirm"]');

        expect(cancelBtn.classes()).toContain('rounded-md');
        expect(cancelBtn.classes()).toContain('text-[13px]');
        expect(cancelBtn.classes()).toContain('border');
        expect(confirmBtn.classes()).toContain('rounded-md');
        expect(confirmBtn.classes()).toContain('text-[13px]');
    });

    it('uses dialog accessibility semantics', () => {
        const wrapper = mountComponent();
        const dialog = wrapper.find('[role="dialog"]');

        expect(dialog.exists()).toBe(true);
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-labelledby')).toBeTruthy();
        expect(dialog.attributes('aria-describedby')).toBeTruthy();
    });

    it('emits cancel on Escape key', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="confirm-modal"]').trigger('keydown', { key: 'Escape' });

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
