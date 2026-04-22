import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';

describe('MarkdownEditor', () => {
    it('renders with visual mode by default', () => {
        const wrapper = mount(MarkdownEditor, {
            props: { modelValue: '# Hello' },
        });
        expect(wrapper.find('[data-testid="editor-mode-visual"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="editor-visual"]').exists()).toBe(true);
    });

    it('switches to source mode', async () => {
        const wrapper = mount(MarkdownEditor, {
            props: { modelValue: '# Hello' },
        });
        await wrapper.find('[data-testid="editor-mode-source"]').trigger('click');
        expect(wrapper.find('[data-testid="editor-source"]').exists()).toBe(true);
    });

    it('renders toolbar with formatting buttons', () => {
        const wrapper = mount(MarkdownEditor, {
            props: { modelValue: '' },
        });
        expect(wrapper.find('[data-testid="editor-toolbar"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="toolbar-bold"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="toolbar-italic"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="toolbar-link"]').exists()).toBe(true);
    });

    it('emits update:modelValue on content change in source mode', async () => {
        const wrapper = mount(MarkdownEditor, {
            props: { modelValue: '' },
        });
        await wrapper.find('[data-testid="editor-mode-source"]').trigger('click');
        const textarea = wrapper.find('[data-testid="editor-source"]');
        await textarea.setValue('# New content');
        expect(wrapper.emitted('update:modelValue')).toBeTruthy();
    });

    it('applies correct height from prop', () => {
        const wrapper = mount(MarkdownEditor, {
            props: { modelValue: '', height: '300px' },
        });
        const editorArea = wrapper.find('[data-testid="editor-content-area"]');
        expect(editorArea.attributes('style')).toContain('300px');
    });
});
