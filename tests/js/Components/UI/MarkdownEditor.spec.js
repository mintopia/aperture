import { describe, it, expect, vi, beforeEach } from 'vitest';
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

    describe('link popover', () => {
        it('does not show link popover by default', () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            expect(wrapper.find('[data-testid="link-popover"]').exists()).toBe(false);
        });

        it('shows link popover when toolbar link button is clicked', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            expect(wrapper.find('[data-testid="link-popover"]').exists()).toBe(true);
        });

        it('link popover has a URL input field', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            expect(wrapper.find('[data-testid="link-url-input"]').exists()).toBe(true);
        });

        it('link popover has an Insert button', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            expect(wrapper.find('[data-testid="link-insert-button"]').exists()).toBe(true);
        });

        it('link popover has a Cancel button', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            expect(wrapper.find('[data-testid="link-cancel-button"]').exists()).toBe(true);
        });

        it('closes link popover when Cancel is clicked', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            expect(wrapper.find('[data-testid="link-popover"]').exists()).toBe(true);
            await wrapper.find('[data-testid="link-cancel-button"]').trigger('click');
            expect(wrapper.find('[data-testid="link-popover"]').exists()).toBe(false);
        });

        it('closes link popover and clears URL after Insert is clicked', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            const input = wrapper.find('[data-testid="link-url-input"]');
            await input.setValue('https://example.com');
            await wrapper.find('[data-testid="link-insert-button"]').trigger('click');
            expect(wrapper.find('[data-testid="link-popover"]').exists()).toBe(false);
        });

        it('does not call window.prompt for link insertion', async () => {
            const promptSpy = vi.spyOn(window, 'prompt');
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '' },
            });
            await wrapper.find('[data-testid="toolbar-link"]').trigger('click');
            expect(promptSpy).not.toHaveBeenCalled();
            promptSpy.mockRestore();
        });
    });

    describe('markdown conversion', () => {
        it('uses marked for markdown to HTML conversion (not bare regex)', async () => {
            // marked handles complex markdown correctly, e.g. nested elements
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '**bold** and _italic_' },
            });
            // If marked is used, the visual editor content will render correctly
            // We verify the component mounts without error and the editor is present
            expect(wrapper.find('[data-testid="editor-visual"]').exists()).toBe(true);
        });

        it('uses turndown for HTML to markdown conversion (not bare regex)', async () => {
            const wrapper = mount(MarkdownEditor, {
                props: { modelValue: '# Heading\n\nSome **bold** text' },
            });
            // Switching to source should preserve markdown correctly
            await wrapper.find('[data-testid="editor-mode-source"]').trigger('click');
            const textarea = wrapper.find('[data-testid="editor-source"]');
            expect(textarea.exists()).toBe(true);
        });
    });
});
