import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import MapBlock from '@/Components/Blocks/MapBlock.vue';

describe('MapBlock', () => {
    it('renders with default coordinates when no settings provided', () => {
        const wrapper = mount(MapBlock, {
            props: { title: 'Map', settings: {} },
        });
        const iframe = wrapper.find('iframe');
        expect(iframe.exists()).toBe(true);
        // Default: London (51.5074, -0.1278), zoom 13
        expect(iframe.attributes('src')).toContain('51.5074');
        expect(iframe.attributes('src')).toContain('-0.1278');
    });

    it('renders with custom lat/lng/zoom from settings', () => {
        const wrapper = mount(MapBlock, {
            props: {
                title: 'Custom Map',
                settings: { lat: 48.8566, lng: 2.3522, zoom: 10 },
            },
        });
        const iframe = wrapper.find('iframe');
        expect(iframe.exists()).toBe(true);
        expect(iframe.attributes('src')).toContain('48.8566');
        expect(iframe.attributes('src')).toContain('2.3522');
    });

    it('has the correct data-testid attributes', () => {
        const wrapper = mount(MapBlock, {
            props: { title: 'Map', settings: {} },
        });
        expect(wrapper.find('[data-testid="block-map"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="map-container"]').exists()).toBe(true);
    });

    it('the iframe src contains the correct coordinates', () => {
        const wrapper = mount(MapBlock, {
            props: {
                title: 'Test',
                settings: { lat: 40.7128, lng: -74.006, zoom: 15 },
            },
        });
        const src = wrapper.find('iframe').attributes('src');
        expect(src).toContain('40.7128');
        expect(src).toContain('-74.006');
    });

    it('displays the title', () => {
        const wrapper = mount(MapBlock, {
            props: { title: 'My Location', settings: {} },
        });
        expect(wrapper.text()).toContain('My Location');
    });
});
