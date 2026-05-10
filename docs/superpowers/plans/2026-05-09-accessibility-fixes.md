# Accessibility Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix four accessibility violations (2 WCAG AA P0, 2 P1) in the UI component library: form error association, StatusPill colour-only indication, missing focus indicators, and missing ARIA labels.

**Architecture:** All fixes are in `resources/js/Components/UI/` — the shared component library. No page-level changes required; fixes flow to all uses automatically.

**Tech Stack:** Vue 3 Composition API, Tailwind CSS 4, Vitest, @vue/test-utils, design system CSS custom properties

---

## Task 1: Link form errors to inputs (P0 — WCAG 1.3.1, 3.3.1)

**Problem:** `FormField.vue` renders an error `<p>` but it has no `id`, and the slotted input has no `aria-describedby` or `aria-invalid`. Screen readers cannot associate the error with the field.

**Files:**
- Modify: `resources/js/Components/UI/FormField.vue`
- Create: `resources/js/Components/UI/__tests__/FormField.test.js`

- [ ] **Step 1.1: Write the failing test**

Create `resources/js/Components/UI/__tests__/FormField.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FormField from '../FormField.vue';

describe('FormField', () => {
    it('links error message to input via aria-describedby', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: {
                default: '<input id="email" type="text" />',
            },
        });

        const error = wrapper.find('[data-testid="form-field-error"]');
        expect(error.exists()).toBe(true);
        expect(error.attributes('id')).toBe('email-error');
    });

    it('exposes error-id as a slot prop for aria-describedby', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email', error: 'Required field' },
            slots: {
                default: ({ errorId, hasError }) =>
                    `<input id="email" :aria-describedby="${errorId}" :aria-invalid="${hasError}" />`,
            },
        });

        const error = wrapper.find('[data-testid="form-field-error"]');
        expect(error.attributes('id')).toBe('email-error');
    });

    it('does not render error element when no error', () => {
        const wrapper = mount(FormField, {
            props: { label: 'Email', name: 'email' },
            slots: { default: '<input id="email" type="text" />' },
        });

        expect(wrapper.find('[data-testid="form-field-error"]').exists()).toBe(false);
    });
});
```

- [ ] **Step 1.2: Run test to verify it fails**

```bash
cd /home/workspace/aperture
npm test -- --run resources/js/Components/UI/__tests__/FormField.test.js
```

Expected: FAIL — `form-field-error` testid not found, no `id` attribute.

- [ ] **Step 1.3: Update FormField.vue**

Replace `resources/js/Components/UI/FormField.vue` with:

```vue
<script setup>
const props = defineProps({
    label: { type: String, required: true },
    name: { type: String, required: true },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const errorId = `${props.name}-error`;
const hasError = !!props.error;
</script>

<template>
    <div :data-testid="'form-field-' + name" class="space-y-1.5">
        <label
            :for="name"
            class="block text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
        >
            {{ label }}
            <span v-if="required" class="text-[var(--color-danger)]" aria-label="required">*</span>
            <span v-else class="ml-1 text-xs font-normal text-[var(--color-text-muted)]">(optional)</span>
        </label>
        <slot :error-id="errorId" :has-error="hasError" />
        <p
            v-if="error"
            :id="errorId"
            data-testid="form-field-error"
            class="text-xs text-[var(--color-danger)]"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
```

- [ ] **Step 1.4: Run tests to verify pass**

```bash
npm test -- --run resources/js/Components/UI/__tests__/FormField.test.js
```

Expected: PASS (3 tests)

- [ ] **Step 1.5: Run lint**

```bash
npm run lint
```

- [ ] **Step 1.6: Commit**

```bash
git add resources/js/Components/UI/FormField.vue resources/js/Components/UI/__tests__/FormField.test.js
git commit -m "fix(a11y): link FormField error to input via scoped slot errorId/hasError

WCAG 1.3.1 / 3.3.1 — error element now has id='{name}-error', exposed
as slot prop errorId and hasError for consumers to apply aria-describedby
and aria-invalid.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 2: Add icon to neutral/muted StatusPill variants (P0 — WCAG 1.4.1)

**Problem:** `StatusPill.vue` neutral and muted variants have `symbol: ''` — colour is the only differentiator from other variants, violating WCAG 1.4.1 (no colour as sole means of conveying information).

**Files:**
- Modify: `resources/js/Components/UI/StatusPill.vue`
- Create: `resources/js/Components/UI/__tests__/StatusPill.test.js`

- [ ] **Step 2.1: Write the failing test**

Create `resources/js/Components/UI/__tests__/StatusPill.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import StatusPill from '../StatusPill.vue';

describe('StatusPill', () => {
    it.each(['success', 'danger', 'warning', 'info', 'neutral', 'muted'])(
        '%s variant renders an accessible symbol',
        (status) => {
            const wrapper = mount(StatusPill, {
                props: { status, label: 'Test' },
            });
            const symbol = wrapper.find('[data-testid="status-symbol"]');
            expect(symbol.exists()).toBe(true);
            expect(symbol.text().trim().length).toBeGreaterThan(0);
        }
    );

    it('neutral symbol has aria-hidden', () => {
        const wrapper = mount(StatusPill, {
            props: { status: 'neutral', label: 'Active' },
        });
        const symbol = wrapper.find('[data-testid="status-symbol"]');
        expect(symbol.attributes('aria-hidden')).toBe('true');
    });
});
```

- [ ] **Step 2.2: Run test to verify it fails**

```bash
npm test -- --run resources/js/Components/UI/__tests__/StatusPill.test.js
```

Expected: FAIL — neutral/muted `symbol` is `''`, `status-symbol` element not rendered.

- [ ] **Step 2.3: Update StatusPill.vue**

In `resources/js/Components/UI/StatusPill.vue`, update the `config` object to add symbols for neutral and muted:

```js
const config = {
    success: {
        bg: 'bg-[var(--color-success)]/14',
        text: 'text-[var(--color-success)]',
        border: 'border-[var(--color-success)]/14',
        symbol: '✓',
    },
    danger: {
        bg: 'bg-[var(--color-danger)]/14',
        text: 'text-[var(--color-danger)]',
        border: 'border-[var(--color-danger)]/14',
        symbol: '✗',
    },
    warning: {
        bg: 'bg-[var(--color-warning)]/14',
        text: 'text-[var(--color-warning)]',
        border: 'border-[var(--color-warning)]/14',
        symbol: '▲',
    },
    info: {
        bg: 'bg-[var(--color-info)]/14',
        text: 'text-[var(--color-info)]',
        border: 'border-[var(--color-info)]/14',
        symbol: '✓',
    },
    neutral: {
        bg: 'bg-[var(--color-text-muted)]/14',
        text: 'text-[var(--color-text-muted)]',
        border: 'border-[var(--color-text-muted)]/14',
        symbol: '–',
    },
    muted: {
        bg: 'bg-[var(--color-text-muted)]/10',
        text: 'text-[var(--color-text-muted)]',
        border: 'border-[var(--color-text-muted)]/10',
        symbol: '·',
    },
};
```

- [ ] **Step 2.4: Run tests**

```bash
npm test -- --run resources/js/Components/UI/__tests__/StatusPill.test.js
```

Expected: PASS

- [ ] **Step 2.5: Commit**

```bash
git add resources/js/Components/UI/StatusPill.vue resources/js/Components/UI/__tests__/StatusPill.test.js
git commit -m "fix(a11y): add symbol to neutral/muted StatusPill variants

WCAG 1.4.1 — neutral gets '–' and muted gets '·' so status is not
conveyed by colour alone.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 3: Restore focus indicators on DataTable and FilterBar (P1 — WCAG 2.4.7)

**Problem:**
- `DataTable.vue` line 69: sort buttons have `focus-visible:outline-none` — removes focus ring entirely
- `DataTable.vue` line 97: clickable rows have `focus-visible:outline-none`
- `FilterBar.vue` line 77: search input has `outline-none`
- `FilterBar.vue` line 88: select dropdowns have `outline-none`

Fix: replace `outline-none` with `focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-1 focus-visible:outline-none` (ring replaces outline, not removes it).

**Files:**
- Modify: `resources/js/Components/UI/DataTable.vue`
- Modify: `resources/js/Components/UI/FilterBar.vue`
- Create: `resources/js/Components/UI/__tests__/DataTable.test.js`

- [ ] **Step 3.1: Write the failing test**

Create `resources/js/Components/UI/__tests__/DataTable.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DataTable from '../DataTable.vue';

const columns = [{ key: 'name', label: 'Name', sortable: true }];
const rows = [{ id: 1, name: 'Alpha' }];

describe('DataTable', () => {
    it('sort button does not have outline-none without a focus ring replacement', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
        });
        const sortBtn = wrapper.find('[data-testid="sort-name"]');
        expect(sortBtn.exists()).toBe(true);
        // Should not use bare outline-none that kills focus visibility
        expect(sortBtn.classes()).not.toContain('outline-none');
    });

    it('sort button has focus-visible ring classes', () => {
        const wrapper = mount(DataTable, {
            props: { columns, rows },
        });
        const sortBtn = wrapper.find('[data-testid="sort-name"]');
        const cls = sortBtn.classes().join(' ');
        expect(cls).toContain('focus-visible:ring-2');
    });
});
```

- [ ] **Step 3.2: Run test to verify it fails**

```bash
npm test -- --run resources/js/Components/UI/__tests__/DataTable.test.js
```

Expected: FAIL — `outline-none` class found, no ring class.

- [ ] **Step 3.3: Fix DataTable.vue**

In `resources/js/Components/UI/DataTable.vue`, find and replace:

**Line ~69** (sort button class):
```
// Before:
class="flex w-full cursor-pointer items-center gap-1 text-left hover:text-[var(--color-text)] focus-visible:outline-none"

// After:
class="flex w-full cursor-pointer items-center gap-1 text-left hover:text-[var(--color-text)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-1 rounded focus-visible:outline-none"
```

**Line ~97** (clickable row class — keep `focus-visible:outline-none` here because the ring is on the tr element itself; add ring):
```
// Before:
'cursor-pointer hover:bg-[var(--color-surface-hover)] focus-visible:bg-[var(--color-surface-hover)] focus-visible:outline-none'

// After:
'cursor-pointer hover:bg-[var(--color-surface-hover)] focus-visible:bg-[var(--color-surface-hover)] focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-primary)]'
```

- [ ] **Step 3.4: Fix FilterBar.vue**

In `resources/js/Components/UI/FilterBar.vue`:

**Search input** (line ~77) — replace `outline-none` with `focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] focus:ring-offset-0`:
```
// Before (excerpt):
class="... outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"

// After:
class="... focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] focus:ring-inset placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
```

**Select dropdowns** (line ~88) — same replacement pattern:
```
// Before (excerpt):
class="... outline-none focus:border-[var(--color-primary)]"

// After:
class="... focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] focus:ring-inset focus:border-[var(--color-primary)]"
```

- [ ] **Step 3.5: Run tests**

```bash
npm test -- --run resources/js/Components/UI/__tests__/DataTable.test.js
npm run lint
```

Expected: PASS, no lint errors.

- [ ] **Step 3.6: Commit**

```bash
git add resources/js/Components/UI/DataTable.vue resources/js/Components/UI/FilterBar.vue resources/js/Components/UI/__tests__/DataTable.test.js
git commit -m "fix(a11y): restore focus indicators on DataTable sort buttons and FilterBar inputs

WCAG 2.4.7 — replaced bare outline-none with focus-visible:ring-2 using
primary colour token. FilterBar search/select inputs now show ring on focus.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 4: Add ARIA labels to filter pill remove buttons and MarkdownEditor toolbar (P1 — WCAG 4.1.2)

**Problem:**
- `FilterBar.vue` pill remove buttons: have `:title` but no `:aria-label` — title is not reliably announced
- `MarkdownEditor.vue` toolbar buttons: have `title` but no `aria-label` — icon-only buttons need explicit label

**Files:**
- Modify: `resources/js/Components/UI/FilterBar.vue`
- Modify: `resources/js/Components/UI/MarkdownEditor.vue`
- Create: `resources/js/Components/UI/__tests__/FilterBar.test.js`

- [ ] **Step 4.1: Write the failing test**

Create `resources/js/Components/UI/__tests__/FilterBar.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FilterBar from '../FilterBar.vue';

const columns = [{ key: 'name', label: 'Name', sortable: false }];
const filters = [{ key: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] }];

describe('FilterBar', () => {
    it('filter pill remove button has aria-label', async () => {
        const wrapper = mount(FilterBar, {
            props: {
                columns,
                filters,
                modelValue: [],
                activeFilters: [{ key: 'status', label: 'Status', value: 'active', displayValue: 'Active' }],
            },
        });

        const removeBtn = wrapper.find('[data-testid="filter-pill-remove-status"]');
        expect(removeBtn.exists()).toBe(true);
        expect(removeBtn.attributes('aria-label')).toBe('Remove Status filter');
    });
});
```

- [ ] **Step 4.2: Run test to verify it fails**

```bash
npm test -- --run resources/js/Components/UI/__tests__/FilterBar.test.js
```

Expected: FAIL — `aria-label` attribute not present on remove button.

- [ ] **Step 4.3: Fix FilterBar.vue remove buttons**

In `resources/js/Components/UI/FilterBar.vue`, find the remove button (around line 104-110) and add `:aria-label`:

```vue
<button
    type="button"
    :data-testid="`filter-pill-remove-${af.key}`"
    :title="`Remove ${af.label} filter`"
    :aria-label="`Remove ${af.label} filter`"
    class="inline-flex h-3.5 w-3.5 cursor-pointer items-center justify-center rounded-full border-none bg-transparent text-xs leading-none text-[var(--color-primary)] transition-colors duration-100 hover:bg-[var(--color-primary)]/25"
    @click="removeFilter(af.key)"
>
    <span aria-hidden="true">&times;</span>
</button>
```

- [ ] **Step 4.4: Fix MarkdownEditor.vue toolbar buttons**

In `resources/js/Components/UI/MarkdownEditor.vue`, each toolbar button already has a `title` attribute (e.g. `title="Bold"`). Add `aria-label` matching the title to each button. Find all toolbar buttons and add the corresponding `aria-label`. Example pattern for Bold:

```vue
<button
    data-testid="toolbar-bold"
    type="button"
    title="Bold"
    aria-label="Bold"
    class="rounded px-1.5 py-0.5 text-[13px] font-bold transition-colors"
    ...
>
```

Apply the same pattern to: Italic, Underline, Strike, H1, H2, Link, Bullet list, Ordered list, Blockquote, Code, Code block, and any other toolbar buttons. The `aria-label` value should match the `title` value exactly.

- [ ] **Step 4.5: Run tests and lint**

```bash
npm test -- --run resources/js/Components/UI/__tests__/FilterBar.test.js
npm run lint
```

Expected: PASS, no lint errors.

- [ ] **Step 4.6: Run full test suite**

```bash
npm test
```

Expected: All tests pass.

- [ ] **Step 4.7: Commit**

```bash
git add resources/js/Components/UI/FilterBar.vue resources/js/Components/UI/MarkdownEditor.vue resources/js/Components/UI/__tests__/FilterBar.test.js
git commit -m "fix(a11y): add aria-label to filter pill remove buttons and MarkdownEditor toolbar

WCAG 4.1.2 — icon-only buttons now have explicit aria-label. FilterBar
remove buttons had title only; MarkdownEditor toolbar buttons get aria-label
matching their title.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```
