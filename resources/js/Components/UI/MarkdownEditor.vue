<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';

const props = defineProps({
    modelValue: { type: String, default: '' },
    height: { type: String, default: '280px' },
    placeholder: { type: String, default: 'Start writing...' },
});

const emit = defineEmits(['update:modelValue']);

const mode = ref('visual');
const sourceContent = ref(props.modelValue);

function markdownToHtml(md) {
    if (!md) return '';
    return md
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2">$1</a>')
        .replace(/^---$/gm, '<hr>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/\n\n/g, '</p><p>')
        .replace(/^(?!<[huo l p hr])(.+)$/gm, '<p>$1</p>');
}

function htmlToMarkdown(html) {
    if (!html) return '';
    return html
        .replace(/<h1>(.*?)<\/h1>/g, '# $1')
        .replace(/<h2>(.*?)<\/h2>/g, '## $1')
        .replace(/<h3>(.*?)<\/h3>/g, '### $1')
        .replace(/<strong>(.*?)<\/strong>/g, '**$1**')
        .replace(/<em>(.*?)<\/em>/g, '*$1*')
        .replace(/<a href="(.*?)">(.*?)<\/a>/g, '[$2]($1)')
        .replace(/<hr\s*\/?>/g, '---')
        .replace(/<li>(.*?)<\/li>/g, '- $1')
        .replace(/<\/?ul>/g, '')
        .replace(/<\/?ol>/g, '')
        .replace(/<\/?p>/g, '\n')
        .replace(/<br\s*\/?>/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

const editor = useEditor({
    content: markdownToHtml(props.modelValue),
    extensions: [
        StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
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

function insertLink() {
    if (!editor.value) return;
    const url = window.prompt('Enter URL');
    if (url) {
        editor.value.chain().focus().extendMarkToLink({ href: url }).run();
    }
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
                class="flex flex-wrap items-center gap-0.5 border-b border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1"
            >
                <button
                    data-testid="toolbar-bold"
                    type="button"
                    title="Bold"
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

                <button
                    data-testid="toolbar-link"
                    type="button"
                    title="Link"
                    class="rounded px-1.5 py-0.5 text-[13px] transition-colors"
                    :class="
                        editor?.isActive('link')
                            ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                            : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]'
                    "
                    @click="insertLink"
                >
                    🔗
                </button>
                <button
                    data-testid="toolbar-code"
                    type="button"
                    title="Code"
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
