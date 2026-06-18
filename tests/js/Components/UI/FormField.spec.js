import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import { h } from 'vue';
import FormField from '@/Components/UI/FormField.vue';

describe('FormField', () => {
    it('renders with required props', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Username', name: 'username' },
        });
        expect(wrapper.text()).toContain('Username');
    });

    it('has data-testid attribute with name', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email' },
        });
        expect(wrapper.find('[data-testid="form-field-email"]').exists()).toBe(true);
    });

    it('renders label with for attribute matching name', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email' },
        });
        const label = wrapper.find('label');
        expect(label.attributes('for')).toBe('email');
    });

    it('shows required indicator when required is true', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', required: true },
        });
        const requiredMark = wrapper.find('[aria-label="required"]');
        expect(requiredMark.exists()).toBe(true);
        expect(requiredMark.text()).toBe('*');
    });

    it('shows optional text when required is false', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Bio', name: 'bio', required: false },
        });
        expect(wrapper.text()).toContain('(optional)');
    });

    it('shows optional text by default', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Bio', name: 'bio' },
        });
        expect(wrapper.text()).toContain('(optional)');
    });

    it('displays error message when error prop is set', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Invalid email' },
        });
        expect(wrapper.text()).toContain('Invalid email');
    });

    it('does not display error paragraph when error is empty', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email' },
        });
        const errorP = wrapper.findAll('p');
        expect(errorP).toHaveLength(0);
    });

    it('renders slot content', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email' },
            slots: { default: '<input type="email" />' },
        });
        expect(wrapper.find('input[type="email"]').exists()).toBe(true);
    });

    // --- Accessibility: error association (WCAG 1.3.1 / 3.3.1) ---
    it('error element has data-testid="form-field-error"', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: { default: '<input id="email" type="text" />' },
        });
        expect(wrapper.find('[data-testid="form-field-error"]').exists()).toBe(true);
    });

    it('error element has id equal to name + "-error"', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: { default: '<input id="email" type="text" />' },
        });
        const error = wrapper.find('[data-testid="form-field-error"]');
        expect(error.attributes('id')).toBe('email-error');
    });

    it('error element has role="alert"', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: { default: '<input id="email" type="text" />' },
        });
        const error = wrapper.find('[data-testid="form-field-error"]');
        expect(error.attributes('role')).toBe('alert');
    });

    it('does not render error element when no error prop', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email' },
            slots: { default: '<input id="email" type="text" />' },
        });
        expect(wrapper.find('[data-testid="form-field-error"]').exists()).toBe(false);
    });

    it('exposes errorId and hasError as scoped slot props', () => {
        let capturedErrorId = null;
        let capturedHasError = null;
        mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: {
                default: (slotProps) => {
                    capturedErrorId = slotProps.errorId;
                    capturedHasError = slotProps.hasError;
                    return '<input id="email" type="text" />';
                },
            },
        });
        expect(capturedErrorId).toBe('email-error');
        expect(capturedHasError).toBe(true);
    });

    it('exposes hasError as false when no error', () => {
        let capturedHasError = null;
        mount(FormField, {
            props: { label: 'Email', name: 'email' },
            slots: {
                default: (slotProps) => {
                    capturedHasError = slotProps.hasError;
                    return '<input id="email" type="text" />';
                },
            },
        });
        expect(capturedHasError).toBe(false);
    });

    it('hasError slot prop updates reactively when error prop changes', async () => {
        let capturedHasError = null;
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: '' },
            slots: {
                default: (slotProps) => {
                    capturedHasError = slotProps.hasError;
                    return h('input', { id: 'email', type: 'text' });
                },
            },
        });
        expect(capturedHasError).toBe(false);
        await wrapper.setProps({ error: 'Required field' });
        expect(capturedHasError).toBe(true);
    });

    it('slot consumer can wire aria-describedby and aria-invalid from slot props', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: {
                default: ({ errorId, hasError }) =>
                    h('input', {
                        id: 'email',
                        'aria-describedby': errorId,
                        'aria-invalid': String(hasError),
                    }),
            },
        });
        const input = wrapper.find('input');
        expect(input.attributes('aria-describedby')).toBe('email-error');
        expect(input.attributes('aria-invalid')).toBe('true');
    });
});
