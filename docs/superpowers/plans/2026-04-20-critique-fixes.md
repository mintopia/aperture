# Admin Panel Critique Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all critique findings across 4 workstreams: confirmation modals for destructive actions, standardized status dot glows, contextual tooltips with visible refresh indicator, and UI polish (button colors, overlay tinting, EmptyState width, table consistency).

**Architecture:** Each workstream touches independent files. Glow standardization touches 8 files. Confirmation modals touch 2 page files. Tooltips touch 3 page files. Polish touches 5+ files. All changes are frontend-only (Vue components + CSS).

**Tech Stack:** Vue 3 (Composition API), Tailwind CSS 4, Vitest, Playwright

---

## File Map

| File | Changes |
|------|---------|
| `resources/js/Pages/Admin/Users/Show.vue` | Add ConfirmModal for block/unblock + standardize glow |
| `resources/js/Pages/Admin/Ips/Show.vue` | Add ConfirmModal for revoke/grant + fix button text-white |
| `resources/js/Components/UI/StatusPill.vue` | Already uses CSS vars for glow - no change needed |
| `resources/js/Pages/Admin/Dashboard.vue` | Already uses CSS vars for glow - no change needed |
| `resources/js/Pages/Admin/Switches/Show.vue` | Already uses CSS vars for glow - no change needed |
| `resources/js/Pages/Admin/Switches/Index.vue` | Standardize glow to CSS vars + fix `$inertia.visit` to `router.visit` |
| `resources/js/Pages/Admin/Switches/Ports/Show.vue` | Standardize glow to CSS vars + make "Updated Xs ago" visible + add tooltip to Refresh/Toggle buttons |
| `resources/js/Pages/Admin/Users/Index.vue` | Standardize glow to CSS vars |
| `resources/js/Components/Blocks/ConnectionStatusBlock.vue` | Standardize glow to CSS vars |
| `resources/js/Components/UI/ConfirmModal.vue` | Brand-tint bg-black/50 overlay |
| `resources/js/Components/Admin/GlobalSearch.vue` | Brand-tint bg-black/50 overlay |
| `resources/js/Components/UI/EmptyState.vue` | Widen max-w from 260px to 320px |
| `resources/js/Components/UI/DataTable.vue` | Add optional sortable column support |
| `resources/js/Pages/Admin/Dhcp/Index.vue` | Migrate to DataTable, fix tracking to 0.05em |
| `resources/js/Pages/Admin/Dhcp/Leases.vue` | Migrate to DataTable, fix tracking to 0.05em |

---

### Task 1: Add Confirmation Modal to User Block/Unblock

**Files:**
- Modify: `resources/js/Pages/Admin/Users/Show.vue`
- Test: `tests/Javascript/Pages/Admin/Users/Show.test.js` (or existing test file)

The User block button at line 56-67 fires `toggleBlock(user)` immediately with no confirmation. Port shutdown (Switches/Ports/Show.vue:294) is the reference pattern — it uses `ConfirmModal` with a slot for `ConnectedDevicesSummary`.

- [ ] **Step 1: Write failing test for block confirmation modal**

Create a Vitest test that verifies:
- Clicking Block shows a ConfirmModal (not the browser confirm)
- The modal displays the user's IP count
- Clicking confirm in the modal triggers the block request
- Clicking cancel closes the modal without blocking

```javascript
// Test: clicking Block button opens confirmation modal
it('shows confirmation modal when clicking Block', async () => {
    const wrapper = mount(UserShow, {
        props: {
            user: { id: 1, nickname: 'TestUser', blocked: false },
            ips: [{ ip: { address: '10.0.0.1', allowed: true } }],
            roles: [],
            auths: [],
            downloaded: 0,
            uploaded: 0,
        },
    });
    await wrapper.find('[data-testid="action-block"]').trigger('click');
    expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run --reporter=verbose tests/Javascript/Pages/Admin/Users/Show.test.js`
Expected: FAIL — clicking block currently fires `router.post` directly, no modal appears.

- [ ] **Step 3: Implement confirmation modal on Users/Show.vue**

Add to `<script setup>`:
```javascript
import { ref } from 'vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const showBlockModal = ref(false);
const blocking = ref(false);

function toggleBlock(user) {
    showBlockModal.value = true;
}

function confirmBlock() {
    blocking.value = true;
    router.post(
        route('admin.users.block', props.user.id),
        { block: props.user.blocked ? 0 : 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                blocking.value = false;
                showBlockModal.value = false;
            },
        },
    );
}
```

Replace the block button click handler from `@click="toggleBlock(user)"` to `@click="toggleBlock(user)"` (same name but now opens modal).

Add modal after the header:
```html
<ConfirmModal
    :show="showBlockModal"
    :title="user.blocked ? 'Unblock User?' : 'Block User?'"
    :message="user.blocked
        ? `This will restore internet access for ${user.nickname} and all their associated IPs.`
        : `This will block internet access for ${user.nickname} and all their associated IPs.`"
    :confirm-label="user.blocked ? 'Unblock' : 'Block'"
    :variant="user.blocked ? 'primary' : 'danger'"
    :loading="blocking"
    @confirm="confirmBlock"
    @cancel="showBlockModal = false"
>
    <p v-if="ips.length" class="mt-3 text-[12px] text-[var(--color-text-muted)]">
        This user has <strong class="text-[var(--color-text-secondary)]">{{ ips.length }}</strong> associated IP{{ ips.length !== 1 ? 's' : '' }}.
    </p>
</ConfirmModal>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run --reporter=verbose tests/Javascript/Pages/Admin/Users/Show.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Users/Show.vue tests/Javascript/Pages/Admin/Users/Show.test.js
git commit -m "feat: add confirmation modal for user block/unblock action"
```

---

### Task 2: Add Confirmation Modal to IP Revoke/Grant Access

**Files:**
- Modify: `resources/js/Pages/Admin/Ips/Show.vue`
- Test: `tests/Javascript/Pages/Admin/Ips/Show.test.js` (or existing test file)

The revoke button at line 50-61 fires `toggleInternet(ip)` immediately. Same pattern as Task 1.

- [ ] **Step 1: Write failing test for revoke confirmation modal**

```javascript
it('shows confirmation modal when clicking Revoke Access', async () => {
    const wrapper = mount(IpShow, {
        props: {
            ip: { id: 1, address: '10.0.0.1', allowed: true },
            users: [{ user: { id: 1, nickname: 'TestUser' } }],
        },
    });
    await wrapper.find('[data-testid="action-revoke"]').trigger('click');
    expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run --reporter=verbose tests/Javascript/Pages/Admin/Ips/Show.test.js`
Expected: FAIL

- [ ] **Step 3: Implement confirmation modal on Ips/Show.vue**

Add to `<script setup>`:
```javascript
import { ref } from 'vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

const showAccessModal = ref(false);
const togglingAccess = ref(false);

function toggleInternet(ip) {
    showAccessModal.value = true;
}

function confirmToggleInternet() {
    togglingAccess.value = true;
    router.post(
        route('admin.ips.internet', props.ip.id),
        { allow: props.ip.allowed ? 0 : 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingAccess.value = false;
                showAccessModal.value = false;
            },
        },
    );
}
```

Add modal in template:
```html
<ConfirmModal
    :show="showAccessModal"
    :title="ip.allowed ? 'Revoke Access?' : 'Grant Access?'"
    :message="ip.allowed
        ? `This will block internet access for ${ip.address}.`
        : `This will allow internet access for ${ip.address}.`"
    :confirm-label="ip.allowed ? 'Revoke Access' : 'Grant Access'"
    :variant="ip.allowed ? 'danger' : 'primary'"
    :loading="togglingAccess"
    @confirm="confirmToggleInternet"
    @cancel="showAccessModal = false"
>
    <p v-if="users.length" class="mt-3 text-[12px] text-[var(--color-text-muted)]">
        This IP is associated with <strong class="text-[var(--color-text-secondary)]">{{ users.length }}</strong> user{{ users.length !== 1 ? 's' : '' }}.
    </p>
</ConfirmModal>
```

Also fix the button `text-white` to `text-[var(--color-bg)]` for theme consistency (line 57).

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run --reporter=verbose tests/Javascript/Pages/Admin/Ips/Show.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Ips/Show.vue tests/Javascript/Pages/Admin/Ips/Show.test.js
git commit -m "feat: add confirmation modal for IP revoke/grant access action"
```

---

### Task 3: Standardize Status Dot Glow Values

**Files to modify:**
- `resources/js/Pages/Admin/Switches/Index.vue` (lines 122-127, 336, 347)
- `resources/js/Pages/Admin/Switches/Ports/Show.vue` (lines 150-151)
- `resources/js/Pages/Admin/Users/Index.vue` (lines 164-165)
- `resources/js/Pages/Admin/Users/Show.vue` (lines 100-101)
- `resources/js/Components/Blocks/ConnectionStatusBlock.vue` (line 26)

**The problem:** Some files use `shadow-[0_0_6px_var(--color-success)]` (CSS variable, correct) while others use `shadow-[0_0_6px_oklch(72%_0.17_155_/_0.5)]` (raw oklch, inconsistent with theme).

**The fix:** Replace all raw oklch glow values with CSS variable equivalents. The StatusPill, Dashboard, and Switches/Show already use the correct pattern.

| Raw oklch value | Replacement CSS var |
|---|---|
| `shadow-[0_0_6px_oklch(72%_0.17_155_/_0.5)]` | `shadow-[0_0_6px_var(--color-success)]` |
| `shadow-[0_0_6px_oklch(65%_0.2_25_/_0.5)]` | `shadow-[0_0_6px_var(--color-danger)]` |
| `shadow-[0_0_6px_oklch(78%_0.15_85_/_0.5)]` | `shadow-[0_0_6px_var(--color-warning)]` |

- [ ] **Step 1: Write test verifying glow class consistency**

A grep-based test or snapshot test that verifies no raw oklch values exist in glow shadow classes across the admin pages.

```javascript
// Test: No raw oklch values in status dot shadows
import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { glob } from 'glob';

describe('Status dot glow consistency', () => {
    it('uses CSS variables, not raw oklch values, in shadow classes', async () => {
        const files = await glob('resources/js/**/*.vue');
        const violations = [];
        for (const file of files) {
            const content = fs.readFileSync(file, 'utf-8');
            const matches = content.match(/shadow-\[0_0_6px_oklch\([^)]+\)\]/g);
            if (matches) {
                violations.push({ file, matches });
            }
        }
        expect(violations).toEqual([]);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run --reporter=verbose tests/Javascript/design-consistency.test.js`
Expected: FAIL — 5 files have raw oklch glow values.

- [ ] **Step 3: Replace all raw oklch glow values**

In each file, replace:
- `shadow-[0_0_6px_oklch(72%_0.17_155_/_0.5)]` with `shadow-[0_0_6px_var(--color-success)]`
- `shadow-[0_0_6px_oklch(65%_0.2_25_/_0.5)]` with `shadow-[0_0_6px_var(--color-danger)]`
- `shadow-[0_0_6px_oklch(78%_0.15_85_/_0.5)]` with `shadow-[0_0_6px_var(--color-warning)]`

Specific locations:
1. **Switches/Index.vue:122-127** — `syncStatusDotClass()` map: completed, running, failed
2. **Switches/Index.vue:347** — inline enabled dot
3. **Switches/Ports/Show.vue:150-151** — `statusDotClass` computed
4. **Users/Index.vue:164-165** — inline blocked/active dot
5. **Users/Show.vue:100-101** — inline ip allowed/denied dot
6. **ConnectionStatusBlock.vue:26** — inline ipAllowed dot (add danger glow too for consistency)

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run --reporter=verbose tests/Javascript/design-consistency.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Switches/Index.vue resources/js/Pages/Admin/Switches/Ports/Show.vue resources/js/Pages/Admin/Users/Index.vue resources/js/Pages/Admin/Users/Show.vue resources/js/Components/Blocks/ConnectionStatusBlock.vue tests/Javascript/design-consistency.test.js
git commit -m "fix: standardize status dot glow values to use CSS variables"
```

---

### Task 4: Visible Refresh Indicator + Contextual Tooltips

**Files:**
- Modify: `resources/js/Pages/Admin/Switches/Ports/Show.vue`

- [ ] **Step 1: Write failing test for visible last-updated indicator**

```javascript
it('displays visible last-updated timestamp', () => {
    const wrapper = mount(PortShow, { props: { /* ... */ } });
    const indicator = wrapper.find('[data-testid="last-updated"]');
    expect(indicator.classes()).not.toContain('sr-only');
    expect(indicator.text()).toContain('just now');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run --reporter=verbose tests/Javascript/Pages/Admin/Switches/Ports/Show.test.js`
Expected: FAIL — element currently has `sr-only` class.

- [ ] **Step 3: Make last-updated visible and add tooltips**

In `Switches/Ports/Show.vue` at line 307, change:
```html
<span data-testid="last-updated" class="sr-only">{{ displayTime }}</span>
```
to:
```html
<span
    data-testid="last-updated"
    class="text-[11px] font-mono text-[var(--color-text-muted)]"
>
    Updated {{ displayTime }}
</span>
```

Position it near the header actions or below the metadata strip. The most natural placement is inline with the Refresh button area.

The Refresh button already has `title="Sync this port's data from the switch"` (line 276). The toggle button uses `toggleTitle` (line 284) which is descriptive. These are adequate for now.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run --reporter=verbose tests/Javascript/Pages/Admin/Switches/Ports/Show.test.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Switches/Ports/Show.vue tests/Javascript/Pages/Admin/Switches/Ports/Show.test.js
git commit -m "feat: make port detail refresh indicator visible"
```

---

### Task 5: Brand-Tint Overlays

**Files:**
- Modify: `resources/js/Components/UI/ConfirmModal.vue` (line 106)
- Modify: `resources/js/Components/Admin/GlobalSearch.vue` (line 80)

- [ ] **Step 1: Write failing test for brand-tinted overlay**

```javascript
it('uses brand-tinted overlay instead of pure black', () => {
    const wrapper = mount(ConfirmModal, { props: { show: true, title: 'Test', message: 'Test' } });
    const overlay = wrapper.find('[data-testid="confirm-modal"]');
    expect(overlay.classes().join(' ')).not.toContain('bg-black');
});
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — overlay currently uses `bg-black/50`.

- [ ] **Step 3: Replace bg-black/50 with brand-tinted dark**

In `ConfirmModal.vue` line 106, replace:
```
bg-black/50
```
with:
```
bg-[oklch(12%_0.006_60_/_0.5)]
```

In `GlobalSearch.vue` line 80, replace:
```
bg-black/50
```
with:
```
bg-[oklch(12%_0.006_60_/_0.5)]
```

This uses the warm charcoal hue (60) from the Dispatch theme at 12% lightness with 50% opacity.

- [ ] **Step 4: Run test to verify it passes**

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/UI/ConfirmModal.vue resources/js/Components/Admin/GlobalSearch.vue
git commit -m "fix: brand-tint modal overlays with warm charcoal instead of pure black"
```

---

### Task 6: EmptyState Width + Button Text Color Consistency

**Files:**
- Modify: `resources/js/Components/UI/EmptyState.vue` (line 16)
- Modify: `resources/js/Pages/Admin/Ips/Show.vue` (line 57)

- [ ] **Step 1: Write failing tests**

```javascript
// EmptyState description should accommodate longer text
it('has sufficient max-width for description text', () => {
    const wrapper = mount(EmptyState, { props: { title: 'Test', description: 'A reasonably long description.' } });
    const desc = wrapper.find('p.max-w-\\[320px\\]');
    expect(desc.exists()).toBe(true);
});

// IP Show button should use theme-aware text color
it('uses theme-aware text color on action button', () => {
    const wrapper = mount(IpShow, { props: { ip: { allowed: true } } });
    const btn = wrapper.find('[data-testid="action-revoke"]');
    expect(btn.classes().join(' ')).toContain('text-[var(--color-bg)]');
    expect(btn.classes().join(' ')).not.toContain('text-white');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Expected: FAIL

- [ ] **Step 3: Apply fixes**

In `EmptyState.vue` line 16, change `max-w-[260px]` to `max-w-[320px]`.

In `Ips/Show.vue` line 57, change `text-white` to `text-[var(--color-bg)]`.

- [ ] **Step 4: Run tests to verify they pass**

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/UI/EmptyState.vue resources/js/Pages/Admin/Ips/Show.vue
git commit -m "fix: widen EmptyState description + use theme-aware button text color"
```

---

### Task 7: Extend DataTable with Optional Sorting

**Files:**
- Modify: `resources/js/Components/UI/DataTable.vue`
- Test: `tests/Javascript/Components/UI/DataTable.test.js`

DataTable currently has no sorting support. Pages that need sorting (DHCP, Switches) build raw tables. Add optional sorting props so these pages can use DataTable.

- [ ] **Step 1: Write failing test for sortable DataTable**

```javascript
it('renders sort buttons when columns have sortable: true', async () => {
    const wrapper = mount(DataTable, {
        props: {
            columns: [
                { key: 'name', label: 'Name', sortable: true },
                { key: 'value', label: 'Value' },
            ],
            rows: [{ name: 'B' }, { name: 'A' }],
        },
    });
    const sortBtn = wrapper.find('[data-testid="sort-name"]');
    expect(sortBtn.exists()).toBe(true);
});

it('emits sort event when clicking sortable header', async () => {
    const wrapper = mount(DataTable, {
        props: {
            columns: [{ key: 'name', label: 'Name', sortable: true }],
            rows: [],
            sortColumn: 'name',
            sortDirection: 'asc',
        },
    });
    await wrapper.find('[data-testid="sort-name"]').trigger('click');
    expect(wrapper.emitted('update:sort-column')).toBeTruthy();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Expected: FAIL — DataTable has no sortable support.

- [ ] **Step 3: Add sorting support to DataTable**

Add new props:
```javascript
sortColumn: { type: String, default: null },
sortDirection: { type: String, default: 'asc', validator: (v) => ['asc', 'desc'].includes(v) },
```

Add new emits:
```javascript
const emit = defineEmits(['update:sort-column', 'update:sort-direction']);

function toggleSort(columnKey) {
    if (props.sortColumn === columnKey) {
        emit('update:sort-direction', props.sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
        emit('update:sort-column', columnKey);
        emit('update:sort-direction', 'asc');
    }
}
```

Update thead to render sortable headers with buttons (matching Switches/Index.vue pattern):
```html
<th v-for="col in columns" :key="col.key" ...>
    <button
        v-if="col.sortable"
        type="button"
        :data-testid="`sort-${col.key}`"
        class="flex w-full cursor-pointer items-center gap-1 text-left hover:text-[var(--color-text)] focus-visible:outline-none"
        :aria-sort="sortColumn === col.key ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"
        @click="toggleSort(col.key)"
    >
        {{ col.label }}
        <span v-if="sortColumn === col.key" class="ml-0.5 text-[var(--color-primary)]">
            {{ sortDirection === 'asc' ? '↑' : '↓' }}
        </span>
    </button>
    <span v-else>{{ col.label }}</span>
</th>
```

Sorting is NOT done inside DataTable — the parent owns the sort state and sorted data (via v-model pattern). DataTable just renders the sort UI and emits events.

- [ ] **Step 4: Run tests to verify they pass**

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/UI/DataTable.vue tests/Javascript/Components/UI/DataTable.test.js
git commit -m "feat: add optional sortable column support to DataTable"
```

---

### Task 8: Migrate DHCP Index Table to DataTable

**Files:**
- Modify: `resources/js/Pages/Admin/Dhcp/Index.vue`
- Test: `tests/Javascript/Pages/Admin/Dhcp/Index.test.js`

- [ ] **Step 1: Write test verifying DataTable is used**

```javascript
it('renders DHCP ranges using DataTable component', () => {
    const wrapper = mount(DhcpIndex, {
        props: { ranges: [{ network: '10.0.0.0/24', start: '10.0.0.1', end: '10.0.0.254', percentage: 50, used: 127, total: 254 }] },
    });
    expect(wrapper.findComponent(DataTable).exists()).toBe(true);
});
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — page uses raw table.

- [ ] **Step 3: Refactor to use DataTable**

Import DataTable, replace raw `<table>` with:
```html
<DataTable
    data-testid="dhcp-ranges"
    :columns="rangeColumns"
    :rows="sortedRanges"
    :sort-column="sortColumn"
    :sort-direction="sortDirection"
    empty-message="No DHCP ranges configured."
    @update:sort-column="sortColumn = $event"
    @update:sort-direction="sortDirection = $event"
>
    <template #row="{ row, index }">
        <!-- existing cell content from current <td> elements -->
    </template>
</DataTable>
```

Keep the existing `sortedRanges` computed and `toggleSort` logic in the parent — DataTable just emits sort events.

- [ ] **Step 4: Run test to verify it passes**

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Dhcp/Index.vue tests/Javascript/Pages/Admin/Dhcp/Index.test.js
git commit -m "refactor: migrate DHCP ranges table to DataTable component"
```

---

### Task 9: Migrate DHCP Leases Table to DataTable

**Files:**
- Modify: `resources/js/Pages/Admin/Dhcp/Leases.vue`
- Test: `tests/Javascript/Pages/Admin/Dhcp/Leases.test.js`

Same approach as Task 8. Fix tracking from `0.08em` to `0.05em` for consistency (DataTable uses `0.05em`).

- [ ] **Step 1: Write test verifying DataTable is used**

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Refactor to use DataTable**

Import DataTable, replace raw `<table>` with DataTable using the same sort event pattern. Keep `filteredLeases`, `toggleSort`, and `showMore` logic in the parent.

- [ ] **Step 4: Run test to verify it passes**

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Dhcp/Leases.vue tests/Javascript/Pages/Admin/Dhcp/Leases.test.js
git commit -m "refactor: migrate DHCP leases table to DataTable component"
```

---

### Task 10: Fix Switches/Index Router + Consistency

**Files:**
- Modify: `resources/js/Pages/Admin/Switches/Index.vue`

- [ ] **Step 1: Write test for router.visit usage**

```javascript
it('uses router.visit for switch row navigation', async () => {
    // Verify router.visit is called (not $inertia.visit)
});
```

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Apply fixes**

In `Switches/Index.vue`:
1. Replace `$inertia.visit(route('admin.switches.show', sw.id))` (lines 320-322) with `router.visit(route('admin.switches.show', sw.id))`
2. `router` is already imported from `@inertiajs/vue3` (not currently — add the import since the page uses `Link` from inertia but not `router` directly)

Note: The Switches/Index table is complex enough (custom sort buttons, caption, filter integration) that migrating it to DataTable would require significant effort for marginal gain. The `$inertia.visit` → `router.visit` fix and glow standardization (Task 3) are sufficient.

- [ ] **Step 4: Run test to verify it passes**

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Switches/Index.vue
git commit -m "fix: use router.visit instead of $inertia.visit in Switches Index"
```

---

### Task 11: Final Quality Pass

- [ ] **Step 1: Run full linting**

```bash
npx eslint resources/js/ --fix
npx prettier --write resources/js/ resources/css/
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 2: Run full test suite**

```bash
npx vitest run
php artisan test --compact
```

- [ ] **Step 3: Run impeccable CLI scan to verify improvements**

```bash
npx impeccable --json resources/js/Pages/Admin/ resources/js/Components/UI/ resources/js/Layouts/
```

Expected: 0 findings (or same 1 finding if bg-black overlay was kept in a non-modified file).

- [ ] **Step 4: Commit any formatting fixes**

```bash
git add -A
git commit -m "chore: formatting and lint fixes"
```
