import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CustomMarkdownBlock from '@/Components/Blocks/CustomMarkdownBlock.vue';

describe('CustomMarkdownBlock', () => {
    it('renders markdown content as HTML', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '**bold text**' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).toContain('<strong>bold text</strong>');
    });

    it('renders headings from markdown', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '## Sub Heading' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).toContain('<h2');
        expect(html).toContain('Sub Heading');
    });

    it('renders lists from markdown', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '- item one\n- item two' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).toContain('<li>');
        expect(html).toContain('item one');
    });

    it('sanitizes dangerous HTML', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '<script>alert("xss")</script>hello' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).not.toContain('<script>');
        expect(html).toContain('hello');
    });

    it('handles empty content gracefully', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '' },
        });
        expect(wrapper.find('[data-testid="block-custom-markdown-content"]').exists()).toBe(true);
    });

    it('renders the title', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'My Title', content: 'test' },
        });
        expect(wrapper.text()).toContain('My Title');
    });
});
