# Switch Port Show Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the admin switch port show page to match the approved layout/interaction spec: minimal top actions, no bounce action, state-aware Shut/Unshut with confirmation, and left-heavy two-column desktop layout.

**Architecture:** Keep all behavior in the existing Vue page component (`Show.vue`) and adjust tests around that component. Use TDD: first update failing specs for new interaction/layout rules, then implement minimal template/script changes to pass, then run targeted + full JS quality checks.

**Tech Stack:** Vue 3 (`<script setup>`), Inertia router helpers, Vitest + Vue Test Utils, existing UI components (`ConfirmModal`, `MetadataStrip`, `SectionHeader`, `TimeSeriesChart`, `ConfigBlock`).

---

## File Structure & Responsibilities

- **Modify:** `resources/js/Pages/Admin/Switches/Ports/Show.vue`
  - Single source for page structure, action behavior, confirmation flow, and section ordering.
- **Modify:** `tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js`
  - Confirmation behavior regression coverage (`Shut/Unshut`, no bounce).
- **Modify:** `tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js`
  - Tooltip/action-area assertions aligned with simplified controls.
- **Modify:** `tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js`
  - Remove duplicate header-status expectation and keep metadata-driven status expectation.
- **Create:** `tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js`
  - Explicit section order/layout contract tests for desktop structure and mobile stacking markers.

---

### Task 1: Lock New UX Rules in Tests (Red)

**Files:**
- Modify: `tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js`
- Modify: `tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js`
- Modify: `tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js`
- Create: `tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js`
- Test: `tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js`
- Test: `tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js`
- Test: `tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js`
- Test: `tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js`

- [ ] **Step 1: Update confirmation tests to remove bounce and enforce always-confirm toggle**

```js
// tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js
it('does not render bounce action', () => {
    const wrapper = mountPage();
    expect(wrapper.find('[data-testid="action-bounce"]').exists()).toBe(false);
});

it('shows confirmation modal when clicking toggle on an up port', async () => {
    const wrapper = mountPage({ port: { admin_status: 'up' } });
    await wrapper.find('[data-testid="action-toggle"]').trigger('click');

    expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Shut Down');
});

it('also shows confirmation modal when clicking toggle on a down port', async () => {
    const wrapper = mountPage({ port: { admin_status: 'down' } });
    await wrapper.find('[data-testid="action-toggle"]').trigger('click');

    expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Enable Port');
});
```

- [ ] **Step 2: Update polish/navigation tests for simplified header controls**

```js
// tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js
it('only renders refresh and toggle actions in header controls', () => {
    const wrapper = mountPage();
    expect(wrapper.find('[data-testid="action-refresh"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="action-toggle"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="action-bounce"]').exists()).toBe(false);
});

// tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js
it('does not render duplicate header status pill', () => {
    const wrapper = mountShow();
    expect(wrapper.find('[data-testid="port-status"]').exists()).toBe(false);
});
```

- [ ] **Step 3: Add explicit layout contract tests**

```js
// tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js
import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

describe('Show — Layout contract', () => {
    it('renders left-primary and right-secondary columns with expected sections', () => {
        const wrapper = mount(Show, { /* same mount stubs/mocks pattern as sibling specs */ });

        expect(wrapper.find('[data-testid="layout-col-left"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-col-right"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="connected-devices-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="running-config-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="errors-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="interface-output-section"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 4: Run updated specs and confirm they fail for the right reasons**

Run:
```bash
npx vitest run \
  tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js
```

Expected: FAIL due to old bounce behavior, duplicate header status, and missing new layout test IDs/section wrappers.

- [ ] **Step 5: Commit failing-test baseline**

```bash
git add tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js
git commit -m "test: codify switch port page redesign behavior"
```

---

### Task 2: Implement Show.vue Redesign (Green)

**Files:**
- Modify: `resources/js/Pages/Admin/Switches/Ports/Show.vue`
- Test: `tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js`
- Test: `tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js`

- [ ] **Step 1: Replace bounce-specific state and actions with a single toggle confirmation flow**

```js
// resources/js/Pages/Admin/Switches/Ports/Show.vue (script setup)
const toggling = ref(false);
const showToggleModal = ref(false);

const isAdminUp = computed(() => props.port.admin_status === 'up');
const toggleActionLabel = computed(() => (isAdminUp.value ? 'Shut Down' : 'Enable'));
const toggleActionTitle = computed(() =>
    isAdminUp.value ? 'Administratively disable this port' : 'Administratively enable this port',
);

function togglePort() {
    showToggleModal.value = true;
}

function confirmToggle() {
    toggling.value = true;
    const routeName = isAdminUp.value ? 'admin.switches.ports.shutdown' : 'admin.switches.ports.enable';

    router.post(
        route(routeName, {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                showToggleModal.value = false;
            },
        },
    );
}
```

- [ ] **Step 2: Simplify header action area and remove duplicate status + bounce zone**

```vue
<!-- Header controls: keep only refresh and toggle -->
<button data-testid="action-refresh" ...>...</button>
<button
  data-testid="action-toggle"
  :title="toggleActionTitle"
  :disabled="toggling"
  @click="togglePort"
>
  {{ toggling ? `${toggleActionLabel}…` : toggleActionLabel }}
</button>

<!-- remove header StatusPill data-testid="port-status" -->
<!-- remove entire danger-zone-port block containing action-bounce -->

<ConfirmModal
  :show="showToggleModal"
  :title="isAdminUp ? 'Shut Down Port?' : 'Enable Port?'"
  :message="isAdminUp
      ? `This will disable ${port.interface}. All connected devices will lose connectivity.`
      : `This will enable ${port.interface}. Connected devices can reconnect once link is up.`"
  :confirm-label="toggleActionLabel"
  :variant="isAdminUp ? 'danger' : 'warning'"
  :loading="toggling"
  @confirm="confirmToggle"
  @cancel="showToggleModal = false"
>
  <ConnectedDevicesSummary :macs="macs" />
</ConfirmModal>
```

- [ ] **Step 3: Reorder into explicit left-wide/right-secondary columns with stable test IDs**

```vue
<div data-testid="layout-main-grid" class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
  <div data-testid="layout-col-left" class="space-y-6">
    <div data-testid="connected-devices-section">...</div>
    <div data-testid="running-config-section">...</div>
  </div>

  <div data-testid="layout-col-right" class="space-y-6">
    <div data-testid="bandwidth-section">...</div>
    <div data-testid="errors-section">...</div>
    <div data-testid="interface-output-section">...</div>
  </div>
</div>
```

- [ ] **Step 4: Run targeted specs and confirm they pass**

Run:
```bash
npx vitest run \
  tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js
```

Expected: PASS for updated interaction and layout contract coverage.

- [ ] **Step 5: Commit implementation**

```bash
git add resources/js/Pages/Admin/Switches/Ports/Show.vue \
  tests/js/Pages/Admin/Switches/Ports/ShowConfirm.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowPolish.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowNavigation.spec.js \
  tests/js/Pages/Admin/Switches/Ports/ShowLayout.spec.js
git commit -m "feat: redesign switch port show layout and controls"
```

---

### Task 3: Quality Gates for JS Surface

**Files:**
- Modify: (none expected; only if lint/format indicates required edits)
- Test: `resources/js/Pages/Admin/Switches/Ports/Show.vue`
- Test: `tests/js/Pages/Admin/Switches/Ports/*.spec.js`

- [ ] **Step 1: Run lint**

Run:
```bash
npm run lint
```

Expected: PASS (0 errors).

- [ ] **Step 2: Run format check**

Run:
```bash
npm run format:check
```

Expected: PASS (all files formatted).  
If it fails, run:
```bash
npm run format
```
Then re-run `npm run format:check`.

- [ ] **Step 3: Run JS tests with coverage for touched area**

Run:
```bash
npx vitest run --coverage tests/js/Pages/Admin/Switches/Ports
```

Expected: PASS with coverage report generated for switch port page specs.

- [ ] **Step 4: Final commit for quality-only fixes (if any)**

```bash
git add -A
git commit -m "chore: satisfy lint and format for switch port redesign"
```

---

## Self-Review

### Spec coverage check
- Minimal top actions only (`Refresh` + `Shut/Unshut`): covered in Task 1 + Task 2.
- No bounce action: covered in Task 1 + Task 2.
- No duplicate header status: covered in Task 1 + Task 2.
- Left-wide two-column desktop + mobile stack intent: covered in Task 2 + Task 1 layout tests.
- Confirmation safety + connected-device impact summary: covered in Task 2 + Task 1 tests.
- Data/empty/error state preservation: maintained by keeping existing section behavior while reorganizing structure in Task 2.

### Placeholder scan
- No TODO/TBD placeholders in tasks.
- Each code step includes concrete snippets and exact file paths.
- Each verification step includes runnable commands and expected outcomes.

### Type/signature consistency
- Single confirmation flow uses `togglePort()` + `confirmToggle()` consistently.
- `data-testid` names used in new test examples match implementation snippets.
