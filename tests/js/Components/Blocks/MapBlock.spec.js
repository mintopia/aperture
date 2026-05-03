import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import MapBlock from '@/Components/Blocks/MapBlock.vue';

describe('MapBlock', () => {
    const defaultProps = {
        title: 'Office Location',
        content: '',
        settings: { lat: 51.5074, lng: -0.1278, zoom: 13 },
        blockContext: {},
    };

    it('renders map container', () => {
        const wrapper = mount(MapBlock, { props: defaultProps });
        expect(wrapper.find('[data-testid="block-map"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="map-container"]').exists()).toBe(true);
    });

    it('shows title when showTitle is true', () => {
        const wrapper = mount(MapBlock, {
            props: { ...defaultProps, settings: { ...defaultProps.settings, showTitle: true } },
        });
        expect(wrapper.find('[data-testid="map-title"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="map-title"]').text()).toBe('Office Location');
    });

    it('hides title when showTitle is false', () => {
        const wrapper = mount(MapBlock, {
            props: { ...defaultProps, settings: { ...defaultProps.settings, showTitle: false } },
        });
        expect(wrapper.find('[data-testid="map-title"]').exists()).toBe(false);
    });

    describe('map container classes when title is hidden', () => {
        it('uses full bleed with all corners rounded when showTitle is false', () => {
            const wrapper = mount(MapBlock, {
                props: { ...defaultProps, settings: { ...defaultProps.settings, showTitle: false } },
            });
            const container = wrapper.find('[data-testid="map-container"]');
            // When title is hidden, the map should have full negative margins on all sides
            // and rounded corners on all sides (rounded-md), not just bottom (rounded-b-md)
            expect(container.classes()).toContain('-m-5');
            expect(container.classes()).toContain('rounded-md');
            expect(container.classes()).not.toContain('rounded-b-md');
            expect(container.classes()).not.toContain('-mx-5');
            expect(container.classes()).not.toContain('-mb-5');
        });

        it('uses bottom-only bleed with bottom corners rounded when showTitle is true', () => {
            const wrapper = mount(MapBlock, {
                props: { ...defaultProps, settings: { ...defaultProps.settings, showTitle: true } },
            });
            const container = wrapper.find('[data-testid="map-container"]');
            // When title is shown, map only bleeds at sides and bottom
            expect(container.classes()).toContain('-mx-5');
            expect(container.classes()).toContain('-mb-5');
            expect(container.classes()).toContain('rounded-b-md');
        });
    });

    it('renders iframe with correct src', () => {
        const wrapper = mount(MapBlock, { props: defaultProps });
        const iframe = wrapper.find('iframe');
        expect(iframe.exists()).toBe(true);
        expect(iframe.attributes('src')).toContain('openstreetmap.org/export/embed.html');
        expect(iframe.attributes('src')).toContain('51.5074');
        expect(iframe.attributes('src')).toContain('-0.1278');
    });

    it('shows title by default when showTitle is not set', () => {
        const wrapper = mount(MapBlock, {
            props: { ...defaultProps, settings: { lat: 51.5074, lng: -0.1278, zoom: 13 } },
        });
        expect(wrapper.find('[data-testid="map-title"]').exists()).toBe(true);
    });
});
