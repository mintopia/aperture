<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';
import { marked } from 'marked';
import TurndownService from 'turndown';

const props = defineProps({
    modelValue: { type: String, default: '' },
    height: { type: String, default: '280px' },
    placeholder: { type: String, default: 'Start writing...' },
});

const emit = defineEmits(['update:modelValue']);

const mode = ref('visual');
const sourceContent = ref(props.modelValue);
const showLinkPopover = ref(false);
const linkUrl = ref('');

const turndown = new TurndownService();

function markdownToHtml(md) {
    if (!md) return '';
    return marked.parse(md);
}

function htmlToMarkdown(html) {
    if (!html) return '';
    return turndown.turndown(html);
}

const editor = useEditor({
    content: markdownToHtml(props.modelValue),
    extensions: [
        StarterKit.configure({ heading: { levels: [1, 2, 3] }, dropcursor: false }),
        Link.configure({ openOnClick: false }),
        Underline,
        Placeholder.configure({ placeholder: props.placeholder }),
    ],
    onUpdate: ({ editor: ed }) => {
        if (mode.value === 'visual') {
            const md = htmlToMarkdown(ed.getHTML());
            sourceContent.value = md;
            emit('update:modelValue', md);
        }
    },
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none focus:outline-none',
            draggable: 'false',
        },
    },
});

watch(
    () => props.modelValue,
    (newValue) => {
        if (mode.value === 'source') {
            sourceContent.value = newValue;
        }
    },
);

function switchToVisual() {
    mode.value = 'visual';
    if (editor.value) {
        editor.value.commands.setContent(markdownToHtml(sourceContent.value), false);
    }
}

function switchToSource() {
    mode.value = 'source';
}

function onSourceInput(event) {
    const value = event.target.value;
    sourceContent.value = value;
    emit('update:modelValue', value);
}

function openLinkPopover() {
    linkUrl.value = '';
    showLinkPopover.value = true;
    nextTick(() => {
        document.querySelector('[data-testid="link-url-input"]')?.focus();
    });
}

function cancelLinkPopover() {
    showLinkPopover.value = false;
    linkUrl.value = '';
}

function insertLink() {
    if (editor.value && linkUrl.value) {
        editor.value.chain().focus().setLink({ href: linkUrl.value }).run();
    }
    showLinkPopover.value = false;
    linkUrl.value = '';
}

onBeforeUnmount(() => {
    if (editor.value) {
        editor.value.destroy();
    }
});
</script>

<template>
    <div data-testid="markdown-editor">
        <div class="mb-1 flex items-center justify-between">
            <slot name="label" />
            <div
                class="flex overflow-hidden rounded border border-[var(--color-border)] bg-[var(--color-surface-alt)] text-[12px]"
            >
                <button
                    data-testid="editor-mode-visual"
                    type="button"
                    class="px-3 py-1 font-medium transition-colors"
                    :class="
                        mode === 'visual'
                            ? 'bg-[var(--color-primary)] text-[var(--color-accent-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="switchToVisual"
                >
                    Visual
                </button>
                <button
                    data-testid="editor-mode-source"
                    type="button"
                    class="px-3 py-1 font-medium transition-colors"
                    :class="
                        mode === 'source'
                            ? 'bg-[var(--color-primary)] text-[var(--color-accent-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="switchToSource"
                >
                    Source
                </button>
            </div>
        </div>

        <div data-testid="editor-container" class="overflow-hidden rounded border border-[var(--color-border)]">
            <!-- Toolbar (visual mode only) -->
            <div
                v-if="mode === 'visual'"
                data-testid="editor-toolbar"
                class="relative flex flex-wrap items-center gap-0.5 border-b border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1"
            >
                <button
                    data-testid="toolbar-bold"
                    type="button"
                    title="Bold"
                    aria-label="Bold"
                    class="rounded px-1.5 py-0.5 text-[13px] font-bold transition-colors"
                    :class="
                        editor?.isActive('bold')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleBold().run()"
                >
                    B
                </button>
                <button
                    data-testid="toolbar-italic"
                    type="button"
                    title="Italic"
                    aria-label="Italic"
                    class="rounded px-1.5 py-0.5 text-[13px] italic transition-colors"
                    :class="
                        editor?.isActive('italic')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleItalic().run()"
                >
                    I
                </button>
                <button
                    data-testid="toolbar-underline"
                    type="button"
                    title="Underline"
                    aria-label="Underline"
                    class="rounded px-1.5 py-0.5 text-[13px] underline transition-colors"
                    :class="
                        editor?.isActive('underline')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleUnderline().run()"
                >
                    U
                </button>
                <button
                    data-testid="toolbar-strike"
                    type="button"
                    title="Strikethrough"
                    aria-label="Strikethrough"
                    class="rounded px-1.5 py-0.5 text-[13px] line-through transition-colors"
                    :class="
                        editor?.isActive('strike')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleStrike().run()"
                >
                    S
                </button>

                <span class="mx-1 h-4 w-px bg-[var(--color-border)]" aria-hidden="true" />

                <button
                    data-testid="toolbar-h1"
                    type="button"
                    title="Heading 1"
                    aria-label="Heading 1"
                    class="rounded px-1.5 py-0.5 text-[11px] font-bold transition-colors"
                    :class="
                        editor?.isActive('heading', { level: 1 })
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleHeading({ level: 1 }).run()"
                >
                    H1
                </button>
                <button
                    data-testid="toolbar-h2"
                    type="button"
                    title="Heading 2"
                    aria-label="Heading 2"
                    class="rounded px-1.5 py-0.5 text-[11px] font-bold transition-colors"
                    :class="
                        editor?.isActive('heading', { level: 2 })
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
                >
                    H2
                </button>
                <button
                    data-testid="toolbar-h3"
                    type="button"
                    title="Heading 3"
                    aria-label="Heading 3"
                    class="rounded px-1.5 py-0.5 text-[11px] font-bold transition-colors"
                    :class="
                        editor?.isActive('heading', { level: 3 })
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleHeading({ level: 3 }).run()"
                >
                    H3
                </button>

                <span class="mx-1 h-4 w-px bg-[var(--color-border)]" aria-hidden="true" />

                <button
                    data-testid="toolbar-bullet-list"
                    type="button"
                    title="Bullet List"
                    aria-label="Bullet List"
                    class="rounded px-1.5 py-0.5 text-[13px] transition-colors"
                    :class="
                        editor?.isActive('bulletList')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleBulletList().run()"
                >
                    •
                </button>
                <button
                    data-testid="toolbar-ordered-list"
                    type="button"
                    title="Ordered List"
                    aria-label="Ordered List"
                    class="rounded px-1.5 py-0.5 text-[11px] transition-colors"
                    :class="
                        editor?.isActive('orderedList')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleOrderedList().run()"
                >
                    1.
                </button>
                <button
                    data-testid="toolbar-blockquote"
                    type="button"
                    title="Blockquote"
                    aria-label="Blockquote"
                    class="rounded px-1.5 py-0.5 text-[13px] transition-colors"
                    :class="
                        editor?.isActive('blockquote')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleBlockquote().run()"
                >
                    "
                </button>

                <span class="mx-1 h-4 w-px bg-[var(--color-border)]" aria-hidden="true" />

                <div class="relative">
                    <button
                        data-testid="toolbar-link"
                        type="button"
                        title="Link"
                        aria-label="Link"
                        class="rounded px-1.5 py-0.5 text-[13px] transition-colors"
                        :class="
                            editor?.isActive('link')
                                ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                                : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                        "
                        @click="openLinkPopover"
                    >
                        Link
                    </button>

                    <!-- Inline link popover -->
                    <div
                        v-if="showLinkPopover"
                        data-testid="link-popover"
                        class="absolute top-full left-0 z-10 mt-1 flex items-center gap-1 rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-2 shadow-md"
                    >
                        <input
                            v-model="linkUrl"
                            data-testid="link-url-input"
                            type="url"
                            placeholder="https://example.com"
                            aria-label="URL"
                            class="rounded border border-[var(--color-border)] bg-[var(--color-input-bg)] px-2 py-1 text-[12px] text-[var(--color-text)] focus:outline-none"
                            @keydown.enter="insertLink"
                            @keydown.esc="cancelLinkPopover"
                        />
                        <button
                            data-testid="link-insert-button"
                            type="button"
                            class="rounded bg-[var(--color-primary)] px-2 py-1 text-[12px] text-[var(--color-accent-text)] transition-colors hover:opacity-90"
                            @click="insertLink"
                        >
                            Insert
                        </button>
                        <button
                            data-testid="link-cancel-button"
                            type="button"
                            class="rounded px-2 py-1 text-[12px] text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                            @click="cancelLinkPopover"
                        >
                            Cancel
                        </button>
                    </div>
                </div>

                <button
                    data-testid="toolbar-code"
                    type="button"
                    title="Code"
                    aria-label="Code"
                    class="rounded px-1.5 py-0.5 font-mono text-[13px] transition-colors"
                    :class="
                        editor?.isActive('code')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="editor?.chain().focus().toggleCode().run()"
                >
                    &lt;/&gt;
                </button>
                <button
                    data-testid="toolbar-hr"
                    type="button"
                    title="Horizontal Rule"
                    aria-label="Horizontal Rule"
                    class="rounded px-1.5 py-0.5 text-[13px] text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                    @click="editor?.chain().focus().setHorizontalRule().run()"
                >
                    —
                </button>
            </div>

            <!-- Editor content area -->
            <div
                data-testid="editor-content-area"
                class="bg-[var(--color-input-bg)] text-[var(--color-text)]"
                :style="{ minHeight: height }"
            >
                <div v-if="mode === 'visual'" data-testid="editor-visual" class="h-full min-h-[inherit]">
                    <EditorContent :editor="editor" class="h-full min-h-[inherit] p-3" />
                </div>
                <textarea
                    v-else
                    data-testid="editor-source"
                    :value="sourceContent"
                    class="h-full min-h-[inherit] w-full resize-none bg-[var(--color-input-bg)] p-3 font-mono text-sm text-[var(--color-text)] focus:outline-none"
                    :style="{ minHeight: height }"
                    @input="onSourceInput"
                />
            </div>
        </div>
    </div>
</template>
