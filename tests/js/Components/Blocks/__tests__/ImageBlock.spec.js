import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ImageBlock from '@/Components/Blocks/ImageBlock.vue';

describe('ImageBlock', () => {
    it('renders image with URL from settings', () => {
        const wrapper = mount(ImageBlock, {
            props: {
                settings: { url: 'https://example.com/photo.jpg', alt: 'A photo' },
            },
        });
        const img = wrapper.find('[data-testid="image-element"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('https://example.com/photo.jpg');
    });

    it('applies object-cover to the image', () => {
        const wrapper = mount(ImageBlock, {
            props: {
                settings: { url: 'https://example.com/photo.jpg', alt: 'A photo' },
            },
        });
        const img = wrapper.find('[data-testid="image-element"]');
        expect(img.classes()).toContain('object-cover');
    });

    it('has correct data-testid', () => {
        const wrapper = mount(ImageBlock, {
            props: {
                settings: { url: 'https://example.com/photo.jpg', alt: 'A photo' },
            },
        });
        expect(wrapper.find('[data-testid="block-image"]').exists()).toBe(true);
    });

    it('shows placeholder when no URL in settings', () => {
        const wrapper = mount(ImageBlock, {
            props: { settings: {} },
        });
        const img = wrapper.find('[data-testid="image-element"]');
        // No image should be rendered, or a placeholder should be shown
        expect(img.exists()).toBe(false);
        // Should show some placeholder content
        expect(wrapper.find('[data-testid="block-image"]').exists()).toBe(true);
        expect(wrapper.text()).toBeTruthy();
    });

    it('uses alt text from settings', () => {
        const wrapper = mount(ImageBlock, {
            props: {
                settings: { url: 'https://example.com/photo.jpg', alt: 'Event banner' },
            },
        });
        const img = wrapper.find('[data-testid="image-element"]');
        expect(img.attributes('alt')).toBe('Event banner');
    });

    it('uses negative margin to create full-bleed effect', () => {
        const wrapper = mount(ImageBlock, {
            props: {
                settings: { url: 'https://example.com/photo.jpg', alt: 'A photo' },
            },
        });
        const block = wrapper.find('[data-testid="block-image"]');
        const style = block.attributes('class') || '';
        // The block should use negative margin to cancel parent p-5 padding
        expect(style).toMatch(/-m-5|margin:\s*-/);
    });
});
