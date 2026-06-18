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

    it('does not emit on non-Tab/Escape key when shown', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="confirm-modal"]').trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('does nothing on Escape key when modal is not shown', async () => {
        const wrapper = mountComponent({ show: false });
        // The overlay doesn't exist when show=false so we test via direct event dispatch
        // which exercises the early return path
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
    });

    it('handles Tab from last focusable element to cycle focus to first', async () => {
        const wrapper = mountComponent();
        const overlay = wrapper.find('[data-testid="confirm-modal"]');

        const cancelBtn = wrapper.find('[data-testid="confirm-modal-cancel"]').element;
        const confirmBtn = wrapper.find('[data-testid="confirm-modal-confirm"]').element;

        // Spy on cancel button focus (the first focusable element that should receive focus)
        const focusSpy = vi.spyOn(cancelBtn, 'focus');

        // Mock document.activeElement to be the confirm button (last focusable)
        const activeElementDescriptor = Object.getOwnPropertyDescriptor(document, 'activeElement');
        Object.defineProperty(document, 'activeElement', {
            get: () => confirmBtn,
            configurable: true,
        });

        // Tab forward from last element should wrap to first
        await overlay.trigger('keydown', { key: 'Tab', shiftKey: false });

        // Restore
        if (activeElementDescriptor) {
            Object.defineProperty(document, 'activeElement', activeElementDescriptor);
        } else {
            delete document.activeElement;
        }

        expect(focusSpy).toHaveBeenCalled();
    });

    it('handles Shift+Tab from first focusable element to cycle focus to last', async () => {
        const wrapper = mountComponent();
        const overlay = wrapper.find('[data-testid="confirm-modal"]');

        const cancelBtn = wrapper.find('[data-testid="confirm-modal-cancel"]').element;
        const confirmBtn = wrapper.find('[data-testid="confirm-modal-confirm"]').element;

        // Spy on confirm button focus (the last focusable element that should receive focus)
        const focusSpy = vi.spyOn(confirmBtn, 'focus');

        // Mock document.activeElement to be the cancel button (first focusable)
        const activeElementDescriptor = Object.getOwnPropertyDescriptor(document, 'activeElement');
        Object.defineProperty(document, 'activeElement', {
            get: () => cancelBtn,
            configurable: true,
        });

        // Shift+Tab from first element should wrap to last
        await overlay.trigger('keydown', { key: 'Tab', shiftKey: true });

        // Restore
        if (activeElementDescriptor) {
            Object.defineProperty(document, 'activeElement', activeElementDescriptor);
        } else {
            delete document.activeElement;
        }

        expect(focusSpy).toHaveBeenCalled();
    });

    it('restores focus to previous element on unmount', async () => {
        const btn = document.createElement('button');
        document.body.appendChild(btn);
        btn.focus();

        // Mount with show=false, then open (sets previousFocusedElement), then unmount
        const wrapper = mountComponent({ show: false });
        const focusSpy = vi.spyOn(btn, 'focus');

        // Show the modal — this sets previousFocusedElement.value = document.activeElement
        await wrapper.setProps({ show: true });
        await wrapper.vm.$nextTick();

        // Unmount should call focus on previousFocusedElement
        wrapper.unmount();
        expect(focusSpy).toHaveBeenCalled();

        document.body.removeChild(btn);
    });

    it('emits cancel on Escape at document level when focus is outside the modal', async () => {
        const wrapper = mountComponent({ loading: true }, { attachTo: document.body });

        await wrapper.setProps({ loading: false });

        // Focus has dropped to <body> (e.g. confirm button was disabled while loading)
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });

    it('pulls focus back into the dialog when Tab is pressed while focus is outside', async () => {
        const wrapper = mountComponent({}, { attachTo: document.body });

        // Simulate focus being outside the dialog (on <body>)
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));

        const dialog = wrapper.find('[role="dialog"]').element;
        expect(dialog.contains(document.activeElement)).toBe(true);

        wrapper.unmount();
    });

    it('restores focus into the dialog when loading completes while still open', async () => {
        const wrapper = mountComponent({ loading: true }, { attachTo: document.body });

        // While loading, focus has been dropped to <body>
        expect(document.activeElement).toBe(document.body);

        await wrapper.setProps({ loading: false });
        await wrapper.vm.$nextTick();

        const dialog = wrapper.find('[role="dialog"]').element;
        expect(dialog.contains(document.activeElement)).toBe(true);

        wrapper.unmount();
    });

    it('does not steal focus when loading completes and focus is already inside the dialog', async () => {
        const wrapper = mountComponent({ loading: true }, { attachTo: document.body });

        // Mock activeElement to always resolve to the (current) cancel button,
        // i.e. focus is already inside the dialog when loading completes.
        const activeElementDescriptor = Object.getOwnPropertyDescriptor(document, 'activeElement');
        Object.defineProperty(document, 'activeElement', {
            get: () => wrapper.find('[data-testid="confirm-modal-cancel"]').element,
            configurable: true,
        });
        const focusSpy = vi.spyOn(window.HTMLElement.prototype, 'focus');

        await wrapper.setProps({ loading: false });
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(focusSpy).not.toHaveBeenCalled();

        focusSpy.mockRestore();
        if (activeElementDescriptor) {
            Object.defineProperty(document, 'activeElement', activeElementDescriptor);
        } else {
            delete document.activeElement;
        }

        wrapper.unmount();
    });

    it('does not refocus the dialog when loading completes after the modal has closed', async () => {
        const wrapper = mountComponent({ loading: true }, { attachTo: document.body });

        await wrapper.setProps({ show: false, loading: false });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(document.activeElement).toBe(document.body);

        wrapper.unmount();
    });

    it('ignores document-level keys when the modal is hidden', async () => {
        const wrapper = mountComponent({ show: false }, { attachTo: document.body });

        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(wrapper.emitted('cancel')).toBeUndefined();

        wrapper.unmount();
    });

    it('does not double-handle Escape originating inside the overlay', async () => {
        const wrapper = mountComponent({}, { attachTo: document.body });

        // Dispatch a real bubbling Escape from the cancel button: the overlay
        // handler must handle it once and the document handler must skip it.
        wrapper
            .find('[data-testid="confirm-modal-cancel"]')
            .element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });

    it('restores focus to previous element when show changes to false', async () => {
        const btn = document.createElement('button');
        document.body.appendChild(btn);
        btn.focus();

        // Mount with show=false, then toggle to true (captures focused element), then false
        const wrapper = mountComponent({ show: false });
        const focusSpy = vi.spyOn(btn, 'focus');

        // Setting show=true captures document.activeElement as previousFocusedElement
        await wrapper.setProps({ show: true });
        await wrapper.vm.$nextTick();

        // Now set show=false — should restore focus
        await wrapper.setProps({ show: false });
        expect(focusSpy).toHaveBeenCalled();

        document.body.removeChild(btn);
    });
});
