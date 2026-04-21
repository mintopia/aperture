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
        settings: {},
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
            props: { block: { ...block, type: 'bandwidth', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(false);
    });

    it('does not show col_span or row_span controls', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-col-span"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="panel-row-span"]').exists()).toBe(false);
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

    it('shows connection strip field editor for connection_strip blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'connection_strip', settings: { fields: [{ label: 'IPv4', value: '{ipv4}' }] } } },
        });
        expect(wrapper.find('[data-testid="panel-fields-editor"]').exists()).toBe(true);
    });

    it('shows add field button for connection_strip blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'connection_strip', settings: { fields: [] } } },
        });
        expect(wrapper.find('[data-testid="panel-add-field"]').exists()).toBe(true);
    });

    it('does not show field editor for non-connection_strip blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'custom_markdown', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-fields-editor"]').exists()).toBe(false);
    });

    it('shows dns filter settings for dns_filter blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'dns_filter', settings: { title: 'Custom', description: 'Desc' } } },
        });
        expect(wrapper.find('[data-testid="panel-settings-title"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="panel-settings-description"]').exists()).toBe(true);
    });

    it('shows template variable reference for connection_strip blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'connection_strip', settings: { fields: [] } } },
        });
        expect(wrapper.find('[data-testid="panel-template-variables"]').exists()).toBe(true);
    });

    it('shows template variable reference for custom_markdown blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'custom_markdown', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-template-variables"]').exists()).toBe(true);
    });

    it('does not show template variable reference for bandwidth blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'bandwidth', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-template-variables"]').exists()).toBe(false);
    });

    it('emits save with settings for connection_strip', async () => {
        const stripBlock = {
            ...block,
            type: 'connection_strip',
            settings: { fields: [{ label: 'IP', value: '{ipv4}' }] },
        };
        const wrapper = mount(EditorSidePanel, { props: { block: stripBlock } });
        await wrapper.find('[data-testid="panel-save"]').trigger('click');
        const emitted = wrapper.emitted('save')[0][0];
        expect(emitted).toHaveProperty('settings');
        expect(emitted.settings).toHaveProperty('fields');
        expect(emitted).not.toHaveProperty('col_span');
        expect(emitted).not.toHaveProperty('row_span');
    });

    it('emits save with settings for dns_filter', async () => {
        const dnsBlock = {
            ...block,
            type: 'dns_filter',
            settings: { title: 'Custom', description: 'Desc' },
        };
        const wrapper = mount(EditorSidePanel, { props: { block: dnsBlock } });
        await wrapper.find('[data-testid="panel-save"]').trigger('click');
        const emitted = wrapper.emitted('save')[0][0];
        expect(emitted).toHaveProperty('settings');
        expect(emitted.settings.title).toBe('Custom');
    });
});
