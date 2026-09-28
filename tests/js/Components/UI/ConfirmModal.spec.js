import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
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

    it.each([
        { variant: 'danger', expectClasses: ['text-[var(--color-danger)]', 'border-[var(--color-danger)]/40'] },
        { variant: 'warning', expectClasses: ['text-[var(--color-warning)]', 'border-[var(--color-warning)]/40'] },
        { variant: 'primary', expectClasses: ['bg-[var(--color-primary)]', 'text-white'] },
    ])('applies $variant variant styling', ({ variant, expectClasses }) => {
        const wrapper = mountComponent({ variant });
        const btn = wrapper.find('[data-testid="confirm-modal-confirm"]');
        expectClasses.forEach((cls) => expect(btn.classes()).toContain(cls));
    });

    it('uses Dispatch modal surface styling', () => {
        const wrapper = mountComponent();
        const dialog = wrapper.find('dialog');

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
        const dialog = wrapper.find('dialog');

        expect(dialog.exists()).toBe(true);
        expect(dialog.element.open).toBe(true);
        expect(dialog.attributes('aria-labelledby')).toBeTruthy();
        expect(dialog.attributes('aria-describedby')).toBeTruthy();
    });

    it('opens the native dialog modally when shown', () => {
        const showModal = vi.spyOn(HTMLDialogElement.prototype, 'showModal');
        const wrapper = mountComponent();

        expect(showModal).toHaveBeenCalledTimes(1);
        expect(wrapper.find('dialog').element.open).toBe(true);

        showModal.mockRestore();
        wrapper.unmount();
    });

    it('emits cancel and keeps the dialog open on the native cancel event', async () => {
        const wrapper = mountComponent();
        const event = new Event('cancel', { cancelable: true });

        wrapper.find('dialog').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.find('dialog').element.open).toBe(true);
    });

    it('emits cancel when the backdrop (dialog element) is clicked', async () => {
        const wrapper = mountComponent();

        await wrapper.find('dialog').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('does not emit cancel when the content is clicked', async () => {
        const wrapper = mountComponent();

        await wrapper.find('[data-testid="confirm-modal-title"]').trigger('click');

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('reopens the dialog if it is closed natively while still shown', () => {
        const wrapper = mountComponent();
        const dialog = wrapper.find('dialog').element;

        dialog.close();

        expect(dialog.open).toBe(true);
    });

    it('closes the native dialog when show becomes false', async () => {
        const wrapper = mountComponent();
        const dialog = wrapper.find('dialog').element;
        const close = vi.spyOn(dialog, 'close');

        await wrapper.setProps({ show: false });

        expect(close).toHaveBeenCalled();
        expect(wrapper.find('dialog').exists()).toBe(false);
    });

    it('closes the native dialog on unmount', () => {
        const wrapper = mountComponent();
        const close = vi.spyOn(wrapper.find('dialog').element, 'close');

        wrapper.unmount();

        expect(close).toHaveBeenCalled();
    });
});
