import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import LinkStripBlock from '@/Components/Blocks/LinkStripBlock.vue';

describe('LinkStripBlock', () => {
    const sampleLinks = [
        { label: 'Home', url: 'https://example.com' },
        { label: 'Docs', url: 'https://docs.example.com' },
        { label: 'Support', url: 'https://support.example.com', icon: 'help' },
    ];

    it('renders links from settings', () => {
        const wrapper = mount(LinkStripBlock, {
            props: { title: 'Links', settings: { links: sampleLinks } },
        });
        const links = wrapper.findAll('a');
        expect(links.length).toBe(3);
    });

    it('each link has correct href and text', () => {
        const wrapper = mount(LinkStripBlock, {
            props: { title: 'Links', settings: { links: sampleLinks } },
        });
        const links = wrapper.findAll('a');
        expect(links[0].attributes('href')).toBe('https://example.com');
        expect(links[0].text()).toContain('Home');
        expect(links[1].attributes('href')).toBe('https://docs.example.com');
        expect(links[1].text()).toContain('Docs');
        expect(links[2].attributes('href')).toBe('https://support.example.com');
        expect(links[2].text()).toContain('Support');
    });

    it('has correct data-testid attributes', () => {
        const wrapper = mount(LinkStripBlock, {
            props: { title: 'Links', settings: { links: sampleLinks } },
        });
        expect(wrapper.find('[data-testid="block-link-strip"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="link-strip-item-0"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="link-strip-item-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="link-strip-item-2"]').exists()).toBe(true);
    });

    it('shows empty message when no links in settings', () => {
        const wrapper = mount(LinkStripBlock, {
            props: { title: 'Links', settings: {} },
        });
        expect(wrapper.find('[data-testid="block-link-strip"]').exists()).toBe(true);
        expect(wrapper.findAll('a').length).toBe(0);
        // Should display some empty/placeholder message
        expect(wrapper.text()).toBeTruthy();
    });

    it('displays the title', () => {
        const wrapper = mount(LinkStripBlock, {
            props: { title: 'Quick Links', settings: { links: sampleLinks } },
        });
        expect(wrapper.text()).toContain('Quick Links');
    });

    it('links open in new tab (target="_blank")', () => {
        const wrapper = mount(LinkStripBlock, {
            props: { title: 'Links', settings: { links: sampleLinks } },
        });
        const links = wrapper.findAll('a');
        links.forEach((link) => {
            expect(link.attributes('target')).toBe('_blank');
        });
    });

    describe('link strip container testid', () => {
        it('has data-testid="link-strip-list" on the links container', () => {
            const wrapper = mount(LinkStripBlock, {
                props: { title: 'Links', settings: { links: sampleLinks } },
            });
            expect(wrapper.find('[data-testid="link-strip-list"]').exists()).toBe(true);
        });
    });

    describe('vertical layout', () => {
        it('uses flex-col class when layout is vertical', () => {
            const wrapper = mount(LinkStripBlock, {
                props: {
                    title: 'Links',
                    settings: { links: sampleLinks, layout: 'vertical' },
                },
            });
            const list = wrapper.find('[data-testid="link-strip-list"]');
            expect(list.exists()).toBe(true);
            expect(list.classes()).toContain('flex-col');
        });

        it('uses flex and items-center classes when layout is horizontal (default)', () => {
            const wrapper = mount(LinkStripBlock, {
                props: {
                    title: 'Links',
                    settings: { links: sampleLinks },
                },
            });
            const list = wrapper.find('[data-testid="link-strip-list"]');
            expect(list.exists()).toBe(true);
            expect(list.classes()).toContain('flex');
            expect(list.classes()).toContain('items-center');
        });

        it('uses flex and items-center classes when layout is explicitly horizontal', () => {
            const wrapper = mount(LinkStripBlock, {
                props: {
                    title: 'Links',
                    settings: { links: sampleLinks, layout: 'horizontal' },
                },
            });
            const list = wrapper.find('[data-testid="link-strip-list"]');
            expect(list.exists()).toBe(true);
            expect(list.classes()).toContain('flex');
            expect(list.classes()).toContain('items-center');
        });

        it('links use border-b in vertical mode instead of border-r', () => {
            const wrapper = mount(LinkStripBlock, {
                props: {
                    title: 'Links',
                    settings: { links: sampleLinks, layout: 'vertical' },
                },
            });
            const linkItems = wrapper.findAll('a');
            // First link (not last) should have border-b, not border-r
            expect(linkItems[0].classes()).toContain('border-b');
            expect(linkItems[0].classes()).not.toContain('border-r');
        });

        it('links use border-r in horizontal mode', () => {
            const wrapper = mount(LinkStripBlock, {
                props: {
                    title: 'Links',
                    settings: { links: sampleLinks, layout: 'horizontal' },
                },
            });
            const linkItems = wrapper.findAll('a');
            // First link (not last) should have border-r
            expect(linkItems[0].classes()).toContain('border-r');
            expect(linkItems[0].classes()).not.toContain('border-b');
        });
    });
});
