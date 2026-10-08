import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BlockGrid from '@/Components/BlockGrid.vue';

vi.stubGlobal(
    'route',
    vi.fn(() => '/mock-route'),
);

describe('BlockGrid', () => {
    const defaultContext = {
        currentIpv4: '10.0.0.1',
        currentIpv6: 'fe80::1',
        internetEnabled: true,
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: { name: 'Player', params: {} },
    };

    it('renders a CSS grid container', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-grid"]').exists()).toBe(true);
    });

    it('exposes block placement as responsive CSS variables', () => {
        const blocks = [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Info',
                content: 'Hello',
                grid_col: 2,
                grid_row: 3,
                col_span: 2,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        const blockEl = wrapper.find('[data-testid="block-custom_markdown-wrapper"]');
        expect(blockEl.attributes('style')).toContain('--block-md-span: 2');
        expect(blockEl.attributes('style')).toContain('--block-col: 2 / span 2');
        expect(blockEl.attributes('style')).toContain('--block-row: 3 / span 1');
        expect(blockEl.attributes('style')).not.toContain('grid-column');
        expect(blockEl.classes()).toContain('min-w-0');
        expect(blockEl.classes()).toContain('xl:[grid-column:var(--block-col)]');
        expect(blockEl.classes()).toContain('md:[grid-column:span_var(--block-md-span)]');
    });

    it('caps the md span at 2 columns', () => {
        const blocks = [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'W',
                content: 'x',
                grid_col: 1,
                grid_row: 1,
                col_span: 3,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, { props: { blocks, blockContext: defaultContext } });
        const style = wrapper.find('[data-testid="block-custom_markdown-wrapper"]').attributes('style');
        expect(style).toContain('--block-md-span: 2');
        expect(style).toContain('--block-col: 1 / span 3');
    });

    it('renders blocks sorted by grid_row then grid_col', () => {
        const mk = (id, grid_row, grid_col) => ({
            id,
            type: 'custom_markdown',
            title: 'T' + id,
            content: 'x',
            grid_col,
            grid_row,
            col_span: 1,
            row_span: 1,
            is_active: true,
            settings: null,
        });
        const blocks = [mk(1, 2, 1), mk(2, 1, 3), mk(3, 1, 1), mk(4, 2, 2)];
        const wrapper = mount(BlockGrid, { props: { blocks, blockContext: defaultContext } });
        const order = wrapper
            .findAll('[data-testid="block-custom_markdown-wrapper"]')
            .map(
                (el) =>
                    el.attributes('style').match(/--block-row: (\d+)/)[1] +
                    ':' +
                    el.attributes('style').match(/--block-col: (\d+)/)[1],
            );
        expect(order).toEqual(['1:1', '1:3', '2:1', '2:2']);
        expect(blocks.map((b) => b.id)).toEqual([1, 2, 3, 4]);
    });

    it('does not render blocks with unknown type', () => {
        const blocks = [
            {
                id: 1,
                type: 'nonexistent',
                title: 'X',
                content: '',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(
            wrapper.findAll('[data-testid]').filter((w) => w.attributes('data-testid')?.includes('wrapper')).length,
        ).toBe(0);
    });

    it('renders multiple blocks at their positions', () => {
        const blocks = [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'A',
                content: 'Hello',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: null,
            },
            {
                id: 2,
                type: 'custom_markdown',
                title: 'B',
                content: 'World',
                grid_col: 2,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.findAll('[data-testid="block-custom_markdown-wrapper"]').length).toBe(2);
    });

    it('passes blockContext to block components', () => {
        const blocks = [
            {
                id: 1,
                type: 'connection_strip',
                title: 'Connection',
                content: '',
                grid_col: 1,
                grid_row: 1,
                col_span: 3,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('10.0.0.1');
    });

    it('renders empty state when no blocks', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.exists()).toBe(true);
    });

    it('sets gridTemplateRows based on maximum row extent', () => {
        const blocks = [
            {
                id: 1,
                type: 'connection_strip',
                title: 'Strip',
                content: '',
                grid_col: 1,
                grid_row: 1,
                col_span: 3,
                row_span: 1,
                is_active: true,
                settings: null,
            },
            {
                id: 2,
                type: 'custom_markdown',
                title: 'Deep',
                content: 'Hello',
                grid_col: 1,
                grid_row: 2,
                col_span: 2,
                row_span: 5,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.attributes('style')).toContain('--block-grid-rows: repeat(6, minmax(80px, auto))');
    });

    it('does not set gridTemplateRows when blocks array is empty', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.attributes('style')).toBeUndefined();
    });

    it('computes gridTemplateRows for single-row blocks', () => {
        const blocks = [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'A',
                content: 'Hello',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.attributes('style')).toContain('--block-grid-rows: repeat(1, minmax(80px, auto))');
    });

    it('uses minmax(80px, auto) for gridTemplateRows to ensure minimum row height', () => {
        const blocks = [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Tall',
                content: 'Hello',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 3,
                is_active: true,
                settings: null,
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.attributes('style')).toContain('--block-grid-rows: repeat(3, minmax(80px, auto))');
    });

    it('renders MapBlock for type "map"', () => {
        const blocks = [
            {
                id: 10,
                type: 'map',
                title: 'Location',
                content: '',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: { lat: 51.5074, lng: -0.1278, zoom: 13 },
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-map-wrapper"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="block-map"]').exists()).toBe(true);
    });

    it('renders ImageBlock for type "image"', () => {
        const blocks = [
            {
                id: 11,
                type: 'image',
                title: 'Banner',
                content: '',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: { url: 'https://example.com/photo.jpg', alt: 'Banner image' },
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-image-wrapper"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="block-image"]').exists()).toBe(true);
    });

    it('renders LinkStripBlock for type "link_strip"', () => {
        const blocks = [
            {
                id: 12,
                type: 'link_strip',
                title: 'Quick Links',
                content: '',
                grid_col: 1,
                grid_row: 1,
                col_span: 3,
                row_span: 1,
                is_active: true,
                settings: { links: [{ label: 'Home', url: 'https://example.com' }] },
            },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-link_strip-wrapper"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="block-link-strip"]').exists()).toBe(true);
    });
});
