import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/UI/FlashMessages.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: vi.fn(() => ({
        props: {
            flash: {},
        },
    })),
}));

describe('FlashMessages', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        usePage.mockReturnValue({
            props: {
                flash: {},
            },
        });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the flash messages container', () => {
        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-messages"]').exists()).toBe(true);
    });

    it('renders nothing when no flash props', () => {
        const wrapper = mount(FlashMessages);
        expect(wrapper.findAll('[role="alert"]')).toHaveLength(0);
    });

    it('renders success message from page props', () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Item saved successfully' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-success"]');
        expect(msg.exists()).toBe(true);
        expect(msg.text()).toContain('Item saved successfully');
    });

    it('renders error message from page props', () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Something went wrong' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-error"]');
        expect(msg.exists()).toBe(true);
        expect(msg.text()).toContain('Something went wrong');
    });

    it('renders warning message from page props', () => {
        usePage.mockReturnValue({
            props: {
                flash: { warning: 'Check your settings' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-warning"]');
        expect(msg.exists()).toBe(true);
        expect(msg.text()).toContain('Check your settings');
    });

    it('renders info message from page props', () => {
        usePage.mockReturnValue({
            props: {
                flash: { info: 'Sync in progress' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-info"]');
        expect(msg.exists()).toBe(true);
        expect(msg.text()).toContain('Sync in progress');
    });

    it('renders multiple messages simultaneously', () => {
        usePage.mockReturnValue({
            props: {
                flash: {
                    success: 'Saved!',
                    error: 'But also failed!',
                    warning: 'Watch out!',
                },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-message-success"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="flash-message-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="flash-message-warning"]').exists()).toBe(true);
        expect(wrapper.findAll('[role="alert"]')).toHaveLength(3);
    });

    it('dismiss button removes message', async () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Error occurred' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-message-error"]').exists()).toBe(true);

        await wrapper.find('[data-testid="flash-dismiss"]').trigger('click');
        expect(wrapper.find('[data-testid="flash-message-error"]').exists()).toBe(false);
    });

    it('auto-dismisses success messages after 5 seconds', async () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Done!' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-message-success"]').exists()).toBe(true);

        vi.advanceTimersByTime(5000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="flash-message-success"]').exists()).toBe(false);
    });

    it('auto-dismisses info messages after 5 seconds', async () => {
        usePage.mockReturnValue({
            props: {
                flash: { info: 'FYI' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-message-info"]').exists()).toBe(true);

        vi.advanceTimersByTime(5000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="flash-message-info"]').exists()).toBe(false);
    });

    it('error messages persist and do not auto-dismiss', async () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Persistent error' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-message-error"]').exists()).toBe(true);

        vi.advanceTimersByTime(10000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="flash-message-error"]').exists()).toBe(true);
    });

    it('warning messages persist and do not auto-dismiss', async () => {
        usePage.mockReturnValue({
            props: {
                flash: { warning: 'Persistent warning' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-message-warning"]').exists()).toBe(true);

        vi.advanceTimersByTime(10000);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="flash-message-warning"]').exists()).toBe(true);
    });

    it('has role="alert" on each message for accessibility', () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Accessible!' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-success"]');
        expect(msg.attributes('role')).toBe('alert');
    });

    it('dismiss button has accessible aria-label', () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Error' },
            },
        });

        const wrapper = mount(FlashMessages);
        const btn = wrapper.find('[data-testid="flash-dismiss"]');
        expect(btn.attributes('aria-label')).toBe('Dismiss error message');
    });

    it('uses Dispatch rounded styling (4px) instead of rounded-lg', () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Done!' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-success"]');
        expect(msg.classes()).toContain('rounded');
        expect(msg.classes()).not.toContain('rounded-lg');
    });

    it('uses Dispatch text sizing (13px)', () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Oops' },
            },
        });

        const wrapper = mount(FlashMessages);
        const text = wrapper.find('[data-testid="flash-message-error"] p');
        expect(text.classes()).toContain('text-[13px]');
    });

    it('clears all timers on unmount', async () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Done!', info: 'Note' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.findAll('[role="alert"]')).toHaveLength(2);

        // Unmount should clear timers without throwing
        wrapper.unmount();

        // Advancing time after unmount should not cause errors
        vi.advanceTimersByTime(5000);
        // No assertions needed beyond not throwing
    });

    it('renders animated SVG checkmark for success messages', () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Done!' },
            },
        });

        const wrapper = mount(FlashMessages);
        const checkmark = wrapper.find('[data-testid="flash-checkmark"]');
        expect(checkmark.exists()).toBe(true);
        expect(checkmark.element.tagName).toBe('svg');
        expect(checkmark.find('.flash-checkmark-path').exists()).toBe(true);
    });

    it('renders text icon for non-success message types', () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Failed' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-checkmark"]').exists()).toBe(false);
        const icon = wrapper.find('[data-testid="flash-message-error"] span[aria-hidden]');
        expect(icon.exists()).toBe(true);
    });

    it('renders auto-dismiss timer bar for success messages', () => {
        usePage.mockReturnValue({
            props: {
                flash: { success: 'Done!' },
            },
        });

        const wrapper = mount(FlashMessages);
        const timer = wrapper.find('[data-testid="flash-timer"]');
        expect(timer.exists()).toBe(true);
        expect(timer.find('.flash-timer-bar').exists()).toBe(true);
    });

    it('renders auto-dismiss timer bar for info messages', () => {
        usePage.mockReturnValue({
            props: {
                flash: { info: 'FYI' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-timer"]').exists()).toBe(true);
    });

    it('does not render timer bar for error messages', () => {
        usePage.mockReturnValue({
            props: {
                flash: { error: 'Bad!' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-timer"]').exists()).toBe(false);
    });

    it('does not render timer bar for warning messages', () => {
        usePage.mockReturnValue({
            props: {
                flash: { warning: 'Watch out!' },
            },
        });

        const wrapper = mount(FlashMessages);
        expect(wrapper.find('[data-testid="flash-timer"]').exists()).toBe(false);
    });

    it('uses semantic info color instead of primary', () => {
        usePage.mockReturnValue({
            props: {
                flash: { info: 'Note' },
            },
        });

        const wrapper = mount(FlashMessages);
        const msg = wrapper.find('[data-testid="flash-message-info"]');
        expect(msg.classes()).toContain('text-[var(--color-info)]');
        expect(msg.classes()).toContain('bg-[var(--color-info)]/10');
        expect(msg.classes()).toContain('border-[var(--color-info)]/20');
    });
});
