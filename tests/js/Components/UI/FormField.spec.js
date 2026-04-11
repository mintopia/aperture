import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
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
});
