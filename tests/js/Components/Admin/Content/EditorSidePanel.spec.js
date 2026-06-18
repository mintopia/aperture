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

    it('pre-populates default fields for connection_strip when settings.fields is empty', () => {
        const wrapper = mountPanel({
            props: {
                block: {
                    ...block,
                    type: 'connection_strip',
                    settings: {},
                },
            },
        });
        // When settings.fields is undefined/empty, the EditorSidePanel should seed
        // the fields ref with the DEFAULT_FIELDS from ConnectionStripBlock so that
        // adding a new field doesn't wipe the defaults
        const fieldLabels = wrapper.findAll('[data-testid^="panel-field-label-"]');
        expect(fieldLabels.length).toBe(4);
        expect(fieldLabels[0].element.value).toBe('IPv4');
        expect(fieldLabels[1].element.value).toBe('IPv6');
        expect(fieldLabels[2].element.value).toBe('MAC Address');
        expect(fieldLabels[3].element.value).toBe('Status');

        const fieldValues = wrapper.findAll('[data-testid^="panel-field-value-"]');
        expect(fieldValues[0].element.value).toBe('{ipv4}');
        expect(fieldValues[1].element.value).toBe('{ipv6}');
        expect(fieldValues[2].element.value).toBe('{mac}');
        expect(fieldValues[3].element.value).toBe('{status}');
    });

    it('pre-populates default fields for connection_strip when settings.fields is undefined', () => {
        const wrapper = mountPanel({
            props: {
                block: {
                    ...block,
                    type: 'connection_strip',
                    settings: { fields: undefined },
                },
            },
        });
        const fieldLabels = wrapper.findAll('[data-testid^="panel-field-label-"]');
        expect(fieldLabels.length).toBe(4);
        expect(fieldLabels[0].element.value).toBe('IPv4');
    });

    it('preserves custom fields for connection_strip when settings.fields is non-empty', () => {
        const wrapper = mountPanel({
            props: {
                block: {
                    ...block,
                    type: 'connection_strip',
                    settings: { fields: [{ label: 'Custom', value: '{ipv4}' }] },
                },
            },
        });
        const fieldLabels = wrapper.findAll('[data-testid^="panel-field-label-"]');
        expect(fieldLabels.length).toBe(1);
        expect(fieldLabels[0].element.value).toBe('Custom');
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

    describe('map block settings', () => {
        const mapBlock = {
            id: 10,
            type: 'map',
            title: 'Office Location',
            content: '',
            col_span: 2,
            row_span: 1,
            is_active: true,
            settings: { lat: 48.8566, lng: 2.3522, zoom: 10, showTitle: false },
        };

        it('shows map settings section for map blocks', () => {
            const wrapper = mountPanel({ props: { block: mapBlock } });
            expect(wrapper.find('[data-testid="panel-map-settings"]').exists()).toBe(true);
        });

        it('does not show map settings for non-map blocks', () => {
            const wrapper = mountPanel({ props: { block } });
            expect(wrapper.find('[data-testid="panel-map-settings"]').exists()).toBe(false);
        });

        it('shows latitude input initialized from settings.lat', () => {
            const wrapper = mountPanel({ props: { block: mapBlock } });
            const input = wrapper.find('[data-testid="panel-map-lat"]');
            expect(input.exists()).toBe(true);
            expect(parseFloat(input.element.value)).toBe(48.8566);
        });

        it('shows longitude input initialized from settings.lng', () => {
            const wrapper = mountPanel({ props: { block: mapBlock } });
            const input = wrapper.find('[data-testid="panel-map-lng"]');
            expect(input.exists()).toBe(true);
            expect(parseFloat(input.element.value)).toBe(2.3522);
        });

        it('shows zoom input initialized from settings.zoom', () => {
            const wrapper = mountPanel({ props: { block: mapBlock } });
            const input = wrapper.find('[data-testid="panel-map-zoom"]');
            expect(input.exists()).toBe(true);
            expect(parseFloat(input.element.value)).toBe(10);
        });

        it('shows show title toggle initialized from settings.showTitle', () => {
            const wrapper = mountPanel({ props: { block: mapBlock } });
            const toggle = wrapper.find('[data-testid="panel-map-show-title"]');
            expect(toggle.exists()).toBe(true);
        });

        it('defaults latitude to 51.5074 when not in settings', () => {
            const blockNoSettings = { ...mapBlock, settings: {} };
            const wrapper = mountPanel({ props: { block: blockNoSettings } });
            const input = wrapper.find('[data-testid="panel-map-lat"]');
            expect(parseFloat(input.element.value)).toBe(51.5074);
        });

        it('defaults longitude to -0.1278 when not in settings', () => {
            const blockNoSettings = { ...mapBlock, settings: {} };
            const wrapper = mountPanel({ props: { block: blockNoSettings } });
            const input = wrapper.find('[data-testid="panel-map-lng"]');
            expect(parseFloat(input.element.value)).toBe(-0.1278);
        });

        it('defaults zoom to 13 when not in settings', () => {
            const blockNoSettings = { ...mapBlock, settings: {} };
            const wrapper = mountPanel({ props: { block: blockNoSettings } });
            const input = wrapper.find('[data-testid="panel-map-zoom"]');
            expect(parseFloat(input.element.value)).toBe(13);
        });

        it('defaults showTitle to true when not in settings', () => {
            const blockNoSettings = { ...mapBlock, settings: {} };
            const wrapper = mountPanel({ props: { block: blockNoSettings } });
            const toggle = wrapper.find('[data-testid="panel-map-show-title"]');
            expect(toggle.exists()).toBe(true);
        });

        it('emits save with map settings in settings object', async () => {
            const wrapper = mountPanel({ props: { block: mapBlock } });
            await wrapper.find('[data-testid="panel-map-lat"]').setValue('40.7128');
            await wrapper.find('[data-testid="panel-map-lng"]').setValue('-74.006');
            await wrapper.find('[data-testid="panel-map-zoom"]').setValue('15');
            await wrapper.find('[data-testid="panel-save"]').trigger('click');
            const emitted = wrapper.emitted('save')[0][0];
            expect(emitted).toHaveProperty('settings');
            expect(emitted.settings).toHaveProperty('lat');
            expect(emitted.settings).toHaveProperty('lng');
            expect(emitted.settings).toHaveProperty('zoom');
            expect(emitted.settings).toHaveProperty('showTitle');
        });
    });

    describe('link strip block settings', () => {
        const linkStripBlock = {
            id: 20,
            type: 'link_strip',
            title: 'Quick Links',
            content: '',
            col_span: 2,
            row_span: 1,
            is_active: true,
            settings: {
                links: [
                    { label: 'Home', url: 'https://example.com' },
                    { label: 'Docs', url: 'https://docs.example.com' },
                ],
                layout: 'horizontal',
            },
        };

        it('shows links editor for link_strip blocks', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-links-editor"]').exists()).toBe(true);
        });

        it('does not show links editor for non-link_strip blocks', () => {
            const wrapper = mountPanel({ props: { block } });
            expect(wrapper.find('[data-testid="panel-links-editor"]').exists()).toBe(false);
        });

        it('shows link label inputs for each link', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-link-label-0"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="panel-link-label-1"]').exists()).toBe(true);
        });

        it('shows link url inputs for each link', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-link-url-0"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="panel-link-url-1"]').exists()).toBe(true);
        });

        it('link label inputs are initialized with correct values', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-link-label-0"]').element.value).toBe('Home');
            expect(wrapper.find('[data-testid="panel-link-label-1"]').element.value).toBe('Docs');
        });

        it('link url inputs are initialized with correct values', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-link-url-0"]').element.value).toBe('https://example.com');
            expect(wrapper.find('[data-testid="panel-link-url-1"]').element.value).toBe('https://docs.example.com');
        });

        it('shows remove button for each link', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-link-remove-0"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="panel-link-remove-1"]').exists()).toBe(true);
        });

        it('shows add link button', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-add-link"]').exists()).toBe(true);
        });

        it('adds a new empty link when add link is clicked', async () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            await wrapper.find('[data-testid="panel-add-link"]').trigger('click');
            expect(wrapper.find('[data-testid="panel-link-label-2"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="panel-link-url-2"]').exists()).toBe(true);
        });

        it('removes a link when remove button is clicked', async () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            await wrapper.find('[data-testid="panel-link-remove-0"]').trigger('click');
            // After removing index 0, the old index 1 ("Docs") becomes index 0
            expect(wrapper.find('[data-testid="panel-link-label-0"]').element.value).toBe('Docs');
            // There should no longer be a second link
            expect(wrapper.find('[data-testid="panel-link-label-1"]').exists()).toBe(false);
        });

        it('shows layout toggle section', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-link-strip-layout"]').exists()).toBe(true);
        });

        it('shows horizontal layout button', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-layout-horizontal"]').exists()).toBe(true);
        });

        it('shows vertical layout button', () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            expect(wrapper.find('[data-testid="panel-layout-vertical"]').exists()).toBe(true);
        });

        it('defaults layout to horizontal when not set', () => {
            const blockNoLayout = {
                ...linkStripBlock,
                settings: { links: linkStripBlock.settings.links },
            };
            const wrapper = mountPanel({ props: { block: blockNoLayout } });
            expect(wrapper.find('[data-testid="panel-link-strip-layout"]').exists()).toBe(true);
        });

        it('emits save with links and layout in settings', async () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            await wrapper.find('[data-testid="panel-save"]').trigger('click');
            const emitted = wrapper.emitted('save')[0][0];
            expect(emitted).toHaveProperty('settings');
            expect(emitted.settings).toHaveProperty('links');
            expect(emitted.settings).toHaveProperty('layout');
            expect(emitted.settings.links).toHaveLength(2);
            expect(emitted.settings.layout).toBe('horizontal');
        });

        it('emits save with updated link data after editing', async () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            await wrapper.find('[data-testid="panel-link-label-0"]').setValue('Updated');
            await wrapper.find('[data-testid="panel-link-url-0"]').setValue('https://updated.com');
            await wrapper.find('[data-testid="panel-save"]').trigger('click');
            const emitted = wrapper.emitted('save')[0][0];
            expect(emitted.settings.links[0].label).toBe('Updated');
            expect(emitted.settings.links[0].url).toBe('https://updated.com');
        });

        it('emits save with vertical layout when selected', async () => {
            const wrapper = mountPanel({ props: { block: linkStripBlock } });
            await wrapper.find('[data-testid="panel-layout-vertical"]').trigger('click');
            await wrapper.find('[data-testid="panel-save"]').trigger('click');
            const emitted = wrapper.emitted('save')[0][0];
            expect(emitted.settings.layout).toBe('vertical');
        });
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
