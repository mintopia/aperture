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

    it('renders the map container and data-testid attributes', () => {
        const wrapper = mount(MapBlock, { props: defaultProps });
        expect(wrapper.find('[data-testid="block-map"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="map-container"]').exists()).toBe(true);
    });

    it.each([
        {
            name: 'settings omit lat/lng/zoom (component defaults)',
            settings: {},
            lat: '51.5074',
            lng: '-0.1278',
        },
        {
            name: 'explicit default coordinates (London)',
            settings: { lat: 51.5074, lng: -0.1278, zoom: 13 },
            lat: '51.5074',
            lng: '-0.1278',
        },
        {
            name: 'custom coordinates (Paris)',
            settings: { lat: 48.8566, lng: 2.3522, zoom: 10 },
            lat: '48.8566',
            lng: '2.3522',
        },
        {
            name: 'custom coordinates (New York)',
            settings: { lat: 40.7128, lng: -74.006, zoom: 15 },
            lat: '40.7128',
            lng: '-74.006',
        },
    ])('renders iframe with correct src for $name', ({ settings, lat, lng }) => {
        const wrapper = mount(MapBlock, { props: { ...defaultProps, settings } });
        const iframe = wrapper.find('iframe');
        expect(iframe.exists()).toBe(true);
        expect(iframe.attributes('src')).toContain('openstreetmap.org/export/embed.html');
        expect(iframe.attributes('src')).toContain(lat);
        expect(iframe.attributes('src')).toContain(lng);
    });

    it.each([
        { name: 'showTitle is true', showTitle: true, expectTitle: true },
        { name: 'showTitle is undefined (defaults to true)', showTitle: undefined, expectTitle: true },
        { name: 'showTitle is false', showTitle: false, expectTitle: false },
    ])('$name -> title rendered: $expectTitle', ({ showTitle, expectTitle }) => {
        const wrapper = mount(MapBlock, {
            props: { ...defaultProps, settings: { ...defaultProps.settings, showTitle } },
        });
        const titleEl = wrapper.find('[data-testid="map-title"]');
        expect(titleEl.exists()).toBe(expectTitle);
        if (expectTitle) {
            expect(titleEl.text()).toBe('Office Location');
            expect(titleEl.element.tagName).toBe('H3');
            expect(wrapper.text()).toContain('Office Location');
        }
    });

    describe('map container classes', () => {
        it.each([
            {
                name: 'showTitle is false: full bleed, all corners rounded',
                showTitle: false,
                toContain: ['-m-5', 'rounded-md'],
                notToContain: ['rounded-b-md', '-mx-5', '-mb-5'],
            },
            {
                name: 'showTitle is true: bottom-only bleed, bottom corners rounded',
                showTitle: true,
                toContain: ['-mx-5', '-mb-5', 'rounded-b-md'],
                notToContain: [],
            },
        ])('$name', ({ showTitle, toContain, notToContain }) => {
            const wrapper = mount(MapBlock, {
                props: { ...defaultProps, settings: { ...defaultProps.settings, showTitle } },
            });
            const container = wrapper.find('[data-testid="map-container"]');
            toContain.forEach((cls) => expect(container.classes()).toContain(cls));
            notToContain.forEach((cls) => expect(container.classes()).not.toContain(cls));
        });
    });
});
