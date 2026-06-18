import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

let mockPageProps = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
}));

import AppLogo from '@/Components/AppLogo.vue';

describe('AppLogo', () => {
    describe('without custom logo', () => {
        beforeEach(() => {
            mockPageProps = { appName: 'My Network' };
        });

        it('renders site title from page props', () => {
            const wrapper = mount(AppLogo);
            expect(wrapper.text()).toBe('My Network');
        });

        it('does not render a logo image', () => {
            const wrapper = mount(AppLogo);
            expect(wrapper.find('[data-testid="app-logo-image"]').exists()).toBe(false);
        });

        it('has data-testid', () => {
            const wrapper = mount(AppLogo);
            expect(wrapper.find('[data-testid="app-logo"]').exists()).toBe(true);
        });
    });

    describe('with custom logo', () => {
        beforeEach(() => {
            mockPageProps = {
                appName: 'My Network',
                theme: { site_logo_url: '/storage/branding/logo.png?v=123' },
            };
        });

        it('renders logo image when site_logo_url is present', () => {
            const wrapper = mount(AppLogo);
            const img = wrapper.find('[data-testid="app-logo-image"]');
            expect(img.exists()).toBe(true);
            expect(img.attributes('src')).toBe('/storage/branding/logo.png?v=123');
        });

        it('logo image has correct alt text', () => {
            const wrapper = mount(AppLogo);
            const img = wrapper.find('[data-testid="app-logo-image"]');
            expect(img.attributes('alt')).toBe('My Network');
        });

        it('still renders site name alongside image', () => {
            const wrapper = mount(AppLogo);
            expect(wrapper.text()).toBe('My Network');
        });

        it('renders both image and text in the same container', () => {
            const wrapper = mount(AppLogo);
            const container = wrapper.find('[data-testid="app-logo"]');
            expect(container.find('img').exists()).toBe(true);
            expect(container.text()).toBe('My Network');
        });
    });

    describe('aperture icon hover delight', () => {
        beforeEach(() => {
            mockPageProps = { appName: 'My Network' };
        });

        it('renders SVG aperture icon with data-testid for hover target', () => {
            const wrapper = mount(AppLogo);
            const icon = wrapper.find('[data-testid="app-logo-icon"]');
            expect(icon.exists()).toBe(true);
            expect(icon.element.tagName).toBe('svg');
        });
    });

    describe('with theme but no logo', () => {
        beforeEach(() => {
            mockPageProps = {
                appName: 'My Network',
                theme: { site_logo_url: null },
            };
        });

        it('does not render logo image when site_logo_url is null', () => {
            const wrapper = mount(AppLogo);
            expect(wrapper.find('[data-testid="app-logo-image"]').exists()).toBe(false);
        });
    });
});
