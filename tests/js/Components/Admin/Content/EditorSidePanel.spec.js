import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import EditorSidePanel from '@/Components/Admin/Content/EditorSidePanel.vue';

const MarkdownEditorStub = {
    template:
        '<div data-testid="markdown-editor"><textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" data-testid="editor-source"></textarea></div>',
    props: ['modelValue'],
    emits: ['update:modelValue'],
};

function mountPanel(options = {}) {
    const { attachTo, ...rest } = options;
    return mount(EditorSidePanel, {
        ...rest,
        attachTo,
        global: {
            stubs: { MarkdownEditor: MarkdownEditorStub },
        },
    });
}

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
        const wrapper = mountPanel({ props: { block } });
        expect(wrapper.text()).toContain('custom_markdown');
    });

    it('renders title input with block title', () => {
        const wrapper = mountPanel({ props: { block } });
        const input = wrapper.find('[data-testid="panel-title-input"]');
        expect(input.element.value).toBe('Welcome');
    });

    it('renders content editor for text blocks', () => {
        const wrapper = mountPanel({ props: { block } });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="markdown-editor"]').exists()).toBe(true);
    });

    it('hides content editor for non-text blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'bandwidth', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="markdown-editor"]').exists()).toBe(false);
    });

    it('does not show col_span or row_span controls', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-col-span"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="panel-row-span"]').exists()).toBe(false);
    });

    it('emits save event with updated data', async () => {
        const wrapper = mountPanel({ props: { block } });
        await wrapper.find('[data-testid="panel-title-input"]').setValue('Updated');
        await wrapper.find('[data-testid="panel-save"]').trigger('click');
        expect(wrapper.emitted('save')).toBeTruthy();
        expect(wrapper.emitted('save')[0][0].title).toBe('Updated');
    });

    it('emits delete event', async () => {
        const wrapper = mountPanel({ props: { block } });
        await wrapper.find('[data-testid="panel-delete"]').trigger('click');
        expect(wrapper.emitted('delete')).toBeTruthy();
    });

    it('emits close event', async () => {
        const wrapper = mountPanel({ props: { block } });
        await wrapper.find('[data-testid="panel-close"]').trigger('click');
        expect(wrapper.emitted('close')).toBeTruthy();
    });

    it('shows connection strip field editor for connection_strip blocks', () => {
        const wrapper = mountPanel({
            props: {
                block: {
                    ...block,
                    type: 'connection_strip',
                    settings: { fields: [{ label: 'IPv4', value: '{ipv4}' }] },
                },
            },
        });
        expect(wrapper.find('[data-testid="panel-fields-editor"]').exists()).toBe(true);
    });

    it('shows add field button for connection_strip blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'connection_strip', settings: { fields: [] } } },
        });
        expect(wrapper.find('[data-testid="panel-add-field"]').exists()).toBe(true);
    });

    it('does not show field editor for non-connection_strip blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'custom_markdown', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-fields-editor"]').exists()).toBe(false);
    });

    it('shows dns filter label input for dns_filter blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'dns_filter', settings: { label: 'Custom' } } },
        });
        expect(wrapper.find('[data-testid="panel-settings-label"]').exists()).toBe(true);
    });

    it('shows content editor for dns_filter blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'dns_filter', content: 'Some desc', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="markdown-editor"]').exists()).toBe(true);
    });

    it('shows template variable reference for connection_strip blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'connection_strip', settings: { fields: [] } } },
        });
        expect(wrapper.find('[data-testid="panel-template-variables"]').exists()).toBe(true);
    });

    it('shows template variable reference for custom_markdown blocks', () => {
        const wrapper = mountPanel({
            props: { block: { ...block, type: 'custom_markdown', settings: {} } },
        });
        expect(wrapper.find('[data-testid="panel-template-variables"]').exists()).toBe(true);
    });

    it('does not show template variable reference for bandwidth blocks', () => {
        const wrapper = mountPanel({
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
        const wrapper = mountPanel({ props: { block: stripBlock } });
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
            settings: { label: 'Custom', description: 'Desc' },
        };
        const wrapper = mountPanel({ props: { block: dnsBlock } });
        await wrapper.find('[data-testid="panel-save"]').trigger('click');
        const emitted = wrapper.emitted('save')[0][0];
        expect(emitted).toHaveProperty('settings');
        expect(emitted.settings.label).toBe('Custom');
    });

    it('has a resize handle on the left edge', () => {
        const wrapper = mountPanel({ props: { block } });
        expect(wrapper.find('[data-testid="panel-resize-handle"]').exists()).toBe(true);
    });

    it('resizes panel width on drag', async () => {
        const wrapper = mountPanel({
            props: { block },
            attachTo: document.body,
        });

        const handle = wrapper.find('[data-testid="panel-resize-handle"]');
        const panel = wrapper.find('[data-testid="editor-side-panel"]');

        // Start drag at x=window.innerWidth - 320 (default width boundary)
        await handle.trigger('mousedown', { clientX: window.innerWidth - 320 });

        // Drag left to widen the panel to 500px
        window.dispatchEvent(new MouseEvent('mousemove', { clientX: window.innerWidth - 500 }));
        await wrapper.vm.$nextTick();

        expect(panel.element.style.width).toBe('500px');

        window.dispatchEvent(new MouseEvent('mouseup'));
        await wrapper.vm.$nextTick();

        wrapper.unmount();
    });

    it('enforces minimum panel width of 280px', async () => {
        const wrapper = mountPanel({
            props: { block },
            attachTo: document.body,
        });

        const handle = wrapper.find('[data-testid="panel-resize-handle"]');
        const panel = wrapper.find('[data-testid="editor-side-panel"]');

        await handle.trigger('mousedown', { clientX: window.innerWidth - 320 });

        // Try to shrink below 280px
        window.dispatchEvent(new MouseEvent('mousemove', { clientX: window.innerWidth - 100 }));
        await wrapper.vm.$nextTick();

        expect(panel.element.style.width).toBe('280px');

        window.dispatchEvent(new MouseEvent('mouseup'));
        wrapper.unmount();
    });

    it('enforces maximum panel width of 50% viewport', async () => {
        const wrapper = mountPanel({
            props: { block },
            attachTo: document.body,
        });

        const handle = wrapper.find('[data-testid="panel-resize-handle"]');
        const panel = wrapper.find('[data-testid="editor-side-panel"]');

        await handle.trigger('mousedown', { clientX: window.innerWidth - 320 });

        // Try to widen beyond 50% of viewport
        window.dispatchEvent(new MouseEvent('mousemove', { clientX: 0 }));
        await wrapper.vm.$nextTick();

        const maxWidth = Math.floor(window.innerWidth / 2);
        expect(parseInt(panel.element.style.width)).toBeLessThanOrEqual(maxWidth);

        window.dispatchEvent(new MouseEvent('mouseup'));
        wrapper.unmount();
    });

    describe('template variable chip click-to-insert', () => {
        beforeEach(() => {
            Object.assign(navigator, {
                clipboard: {
                    writeText: vi.fn().mockResolvedValue(undefined),
                },
            });
        });

        afterEach(() => {
            vi.restoreAllMocks();
        });

        it('inserts variable key at cursor position of last focused field value input', async () => {
            const stripBlock = {
                ...block,
                type: 'connection_strip',
                settings: { fields: [{ label: 'Address', value: 'IP: ' }] },
            };
            const wrapper = mountPanel({
                props: { block: stripBlock },
                attachTo: document.body,
            });

            // Expand the variables section
            await wrapper.find('[data-testid="panel-variables-toggle"]').trigger('click');

            // Focus the field value input and set cursor at end
            const fieldInput = wrapper.find('[data-testid="panel-field-value-0"]');
            await fieldInput.trigger('focus');
            fieldInput.element.setSelectionRange(4, 4);

            // Click a variable chip
            const chip = wrapper.find('[data-testid="panel-variable-{ipv4}"]');
            await chip.trigger('click');

            // Value should have the variable key inserted at position 4
            expect(fieldInput.element.value).toBe('IP: {ipv4}');

            wrapper.unmount();
        });

        it('copies variable key to clipboard when no input is focused', async () => {
            const wrapper = mountPanel({
                props: { block: { ...block, type: 'custom_markdown', settings: {} } },
                attachTo: document.body,
            });

            // Expand the variables section
            await wrapper.find('[data-testid="panel-variables-toggle"]').trigger('click');

            // Click a variable chip without focusing any input first
            const chip = wrapper.find('[data-testid="panel-variable-{ipv4}"]');
            await chip.trigger('click');

            expect(navigator.clipboard.writeText).toHaveBeenCalledWith('{ipv4}');

            wrapper.unmount();
        });

        it('shows Copied! tooltip after clipboard copy and clears it', async () => {
            vi.useFakeTimers();
            const wrapper = mountPanel({
                props: { block: { ...block, type: 'custom_markdown', settings: {} } },
                attachTo: document.body,
            });

            // Expand the variables section
            await wrapper.find('[data-testid="panel-variables-toggle"]').trigger('click');

            // Click a variable chip without focusing any input
            const chip = wrapper.find('[data-testid="panel-variable-{ipv4}"]');
            await chip.trigger('click');
            await wrapper.vm.$nextTick();

            // Tooltip should be visible
            expect(wrapper.find('[data-testid="panel-variable-copied-{ipv4}"]').exists()).toBe(true);

            // Advance timer to clear the tooltip
            vi.advanceTimersByTime(1500);
            await wrapper.vm.$nextTick();

            expect(wrapper.find('[data-testid="panel-variable-copied-{ipv4}"]').exists()).toBe(false);

            vi.useRealTimers();
            wrapper.unmount();
        });

        it('inserts variable key at cursor position of last focused field value input (replacing selection)', async () => {
            const stripBlock = {
                ...block,
                type: 'connection_strip',
                settings: { fields: [{ label: 'Address', value: 'Hello world' }] },
            };
            const wrapper = mountPanel({
                props: { block: stripBlock },
                attachTo: document.body,
            });

            // Expand the variables section
            await wrapper.find('[data-testid="panel-variables-toggle"]').trigger('click');

            // Focus the field value input and select "world"
            const fieldInput = wrapper.find('[data-testid="panel-field-value-0"]');
            await fieldInput.trigger('focus');
            fieldInput.element.setSelectionRange(6, 11);

            // Click a variable chip
            const chip = wrapper.find('[data-testid="panel-variable-{ipv4}"]');
            await chip.trigger('click');

            // "world" should be replaced with the variable key
            expect(fieldInput.element.value).toBe('Hello {ipv4}');

            wrapper.unmount();
        });
    });
});
