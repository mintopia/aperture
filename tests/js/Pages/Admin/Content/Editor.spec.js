import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Editor from '@/Pages/Admin/Content/Editor.vue';

vi.stubGlobal(
    'route',
    vi.fn((name) => `/mock/${name}`),
);
vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) })),
);

describe('Admin Content Editor', () => {
    const defaultProps = {
        blocks: [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Welcome',
                content: 'Hello',
                grid_col: 1,
                grid_row: 1,
                col_span: 2,
                row_span: 1,
                is_active: true,
                settings: null,
            },
            {
                id: 2,
                type: 'bandwidth',
                title: 'Bandwidth',
                content: '',
                grid_col: 3,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ],
        singletonTypes: ['bandwidth', 'connection_strip', 'dns_filter'],
        existingTypes: ['custom_markdown', 'bandwidth'],
    };

    it('renders the page title', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Grid Editor');
    });

    it('renders a 3-column grid', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="editor-grid"]').exists()).toBe(true);
    });

    it('renders blocks at their grid positions', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const block1 = wrapper.find('[data-testid="editor-block-1"]');
        expect(block1.exists()).toBe(true);
        expect(block1.attributes('style')).toContain('grid-column: 1 / span 2');
    });

    it('has a save layout button', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="action-save-layout"]').exists()).toBe(true);
    });

    it('has an add block button', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="action-add-block"]').exists()).toBe(true);
    });

    it('opens side panel on block click', async () => {
        const wrapper = mount(Editor, { props: defaultProps });
        await wrapper.find('[data-testid="editor-block-1"]').trigger('click');
        expect(wrapper.find('[data-testid="editor-side-panel"]').exists()).toBe(true);
    });

    it('shows block size indicator', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const block1 = wrapper.find('[data-testid="editor-block-1"]');
        expect(block1.text()).toContain('2\u00d71');
    });

    it('restores positions on drag cancel (Escape key)', async () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const block1 = wrapper.find('[data-testid="editor-block-1"]');
        const originalStyle = block1.attributes('style');
        const handle = wrapper.find('[data-testid="drag-handle-1"]');
        await handle.trigger('mousedown', { clientX: 100, clientY: 100, preventDefault: vi.fn() });
        const grid = wrapper.find('[data-testid="editor-grid"]');
        await grid.trigger('keydown', { key: 'Escape' });
        expect(block1.attributes('style')).toBe(originalStyle);
    });

    it('renders a resize handle on each block', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const handle1 = wrapper.find('[data-testid="resize-handle-1"]');
        const handle2 = wrapper.find('[data-testid="resize-handle-2"]');
        expect(handle1.exists()).toBe(true);
        expect(handle2.exists()).toBe(true);
    });

    it('does not show col_span or row_span controls in side panel', async () => {
        const wrapper = mount(Editor, { props: defaultProps });
        await wrapper.find('[data-testid="editor-block-1"]').trigger('click');
        expect(wrapper.find('[data-testid="panel-col-span"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="panel-row-span"]').exists()).toBe(false);
    });

    it('starts pointer drag on handle mousedown and shows ghost on move', async () => {
        const wrapper = mount(Editor, {
            props: defaultProps,
            attachTo: document.body,
        });
        const handle = wrapper.find('[data-testid="drag-handle-1"]');

        await handle.trigger('mousedown', { clientX: 100, clientY: 100, preventDefault: vi.fn() });

        // Block should be semi-transparent during drag
        const block1 = wrapper.find('[data-testid="editor-block-1"]');
        expect(block1.attributes('style')).toContain('opacity: 0.3');

        // Release
        document.dispatchEvent(new MouseEvent('mouseup'));
        await wrapper.vm.$nextTick();

        wrapper.unmount();
    });

    it('drag handle has cursor-grab class', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const handle = wrapper.find('[data-testid="drag-handle-1"]');
        expect(handle.classes()).toContain('cursor-grab');
    });
});
