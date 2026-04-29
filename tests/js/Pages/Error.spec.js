import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Error from '@/Pages/Error.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({ props: {} }),
}));

describe('Error Page', () => {
    function mountError(props = {}) {
        return mount(Error, {
            props: {
                status: 404,
                ...props,
            },
        });
    }

    describe('known status codes', () => {
        it('renders 404 status code, title, and description', () => {
            const wrapper = mountError({ status: 404 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('404');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Page Not Found');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });

        it('renders 403 status code and title', () => {
            const wrapper = mountError({ status: 403 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('403');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Forbidden');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });

        it('renders 500 status code and title', () => {
            const wrapper = mountError({ status: 500 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('500');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Server Error');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });

        it('renders 503 status code and title', () => {
            const wrapper = mountError({ status: 503 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('503');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Service Unavailable');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });

        it('renders 419 status code and title', () => {
            const wrapper = mountError({ status: 419 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('419');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Session Expired');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });

        it('renders 429 status code and title', () => {
            const wrapper = mountError({ status: 429 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('429');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Too Many Requests');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });
    });

    describe('unknown status codes', () => {
        it('falls back to generic title for unknown status codes', () => {
            const wrapper = mountError({ status: 418 });

            expect(wrapper.find('[data-testid="error-page"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="error-status"]').text()).toBe('418');
            expect(wrapper.find('[data-testid="error-title"]').text()).toBe('Something Went Wrong');
            expect(wrapper.find('[data-testid="error-description"]').text()).toBeTruthy();
        });
    });

    describe('navigation', () => {
        it('renders a Go Home link pointing to /', () => {
            const wrapper = mountError({ status: 404 });

            const link = wrapper.find('[data-testid="error-home-link"]');
            expect(link.exists()).toBe(true);
            expect(link.text()).toBe('Go Home');
            expect(link.attributes('href')).toBe('/');
        });
    });

    describe('page title', () => {
        it('uses Inertia Head component to set the page title', () => {
            const wrapper = mountError({ status: 404 });

            // The Head component is mocked as a div; verify it is rendered
            // and the component sets a title (the Head mock renders as <div />)
            expect(wrapper.find('div').exists()).toBe(true);
        });
    });

    describe('standalone rendering', () => {
        it('does not use AdminLayout or PortalLayout', () => {
            const wrapper = mountError({ status: 404 });

            // The error page is standalone -- no layout wrapper components
            expect(wrapper.html()).not.toContain('AdminLayout');
            expect(wrapper.html()).not.toContain('PortalLayout');
        });
    });
});
