import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import EditorSidePanel from '@/Components/Admin/Content/EditorSidePanel.vue';

describe('EditorSidePanel', () => {
    const block = {
        id: 1,
        type: 'custom_markdown',
        title: 'Welcome',
        content: 'Hello world',
        col_span: 2,
        row_span: 1,
        is_active: true,
    };

    it('renders block type as read-only', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.text()).toContain('custom_markdown');
    });

    it('renders title input with block title', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        const input = wrapper.find('[data-testid="panel-title-input"]');
        expect(input.element.value).toBe('Welcome');
    });

    it('renders content textarea for text blocks', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(true);
    });

    it('hides content textarea for non-text blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'bandwidth' } },
        });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(false);
    });

    it('renders col span selector', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.find('[data-testid="panel-col-span"]').exists()).toBe(true);
    });

    it('renders row span input', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.find('[data-testid="panel-row-span"]').exists()).toBe(true);
    });

    it('emits save event with updated data', async () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        await wrapper.find('[data-testid="panel-title-input"]').setValue('Updated');
        await wrapper.find('[data-testid="panel-save"]').trigger('click');
        expect(wrapper.emitted('save')).toBeTruthy();
        expect(wrapper.emitted('save')[0][0].title).toBe('Updated');
    });

    it('emits delete event', async () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        await wrapper.find('[data-testid="panel-delete"]').trigger('click');
        expect(wrapper.emitted('delete')).toBeTruthy();
    });

    it('emits close event', async () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        await wrapper.find('[data-testid="panel-close"]').trigger('click');
        expect(wrapper.emitted('close')).toBeTruthy();
    });
});
