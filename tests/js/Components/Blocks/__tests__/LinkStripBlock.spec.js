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
});
