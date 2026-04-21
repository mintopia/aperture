import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BlockGrid from '@/Components/BlockGrid.vue';

// Mock route function globally
vi.stubGlobal(
    'route',
    vi.fn(() => '/mock-route'),
);

describe('BlockGrid', () => {
    const defaultContext = {
        currentIp: '192.168.1.42',
        ipAllowed: true,
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: {},
    };

    it('renders a CSS grid container', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-grid"]').exists()).toBe(true);
    });

    it('positions blocks using grid-column and grid-row styles', () => {
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
        expect(blockEl.attributes('style')).toContain('grid-column: 2 / span 2');
        expect(blockEl.attributes('style')).toContain('grid-row: 3 / span 1');
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
        expect(wrapper.text()).toContain('192.168.1.42');
    });

    it('renders empty state when no blocks', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.exists()).toBe(true);
    });
});
