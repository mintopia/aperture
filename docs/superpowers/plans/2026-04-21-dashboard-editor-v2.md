# Dashboard Editor V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Overhaul the Dashboard Content & Layout Editor — remove dead block types, fix markdown rendering, add settings-driven block configuration with template variables, and implement grid drag-reflow with resize handles.

**Architecture:** Four phases executed sequentially: (1) removals and route consolidation, (2) markdown rendering, (3) settings UI and template variable reference, (4) grid interactions (reflow + resize handles). Each phase produces a working, testable state.

**Tech Stack:** Laravel 12 (PHP 8.5), Vue 3 (Composition API), Inertia.js, Tailwind CSS, `marked` + `dompurify` (new npm deps), Vitest, PHPUnit

**Design Spec:** `docs/superpowers/specs/2026-04-21-dashboard-editor-v2-design.md`

---

## Phase 1: Removals & Route Consolidation

### Task 1: Migration to delete orphaned block rows

**Files:**
- Create: `database/migrations/XXXX_XX_XX_XXXXXX_delete_removed_block_types.php` (use `php artisan make:migration`)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/ContentControllerTest.php`:

```php
public function test_migration_removes_orphaned_block_types(): void
{
    Queue::fake();
    ContentBlock::factory()->create(['type' => 'event_info']);
    ContentBlock::factory()->create(['type' => 'network_stats']);
    ContentBlock::factory()->create(['type' => 'connection_status']);
    ContentBlock::factory()->create(['type' => 'custom_markdown']);

    $this->artisan('migrate', ['--path' => 'database/migrations', '--realpath' => true]);

    $this->assertDatabaseMissing('content_blocks', ['type' => 'event_info']);
    $this->assertDatabaseMissing('content_blocks', ['type' => 'network_stats']);
    $this->assertDatabaseMissing('content_blocks', ['type' => 'connection_status']);
    $this->assertDatabaseHas('content_blocks', ['type' => 'custom_markdown']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_migration_removes_orphaned_block_types`
Expected: FAIL — migration doesn't exist yet

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration delete_removed_block_types --no-interaction`

Then replace the migration contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_blocks')
            ->whereIn('type', ['event_info', 'network_stats', 'connection_status'])
            ->delete();
    }

    public function down(): void
    {
        // Rows cannot be restored — this is a data cleanup migration
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=test_migration_removes_orphaned_block_types`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*_delete_removed_block_types.php tests/Feature/Admin/ContentControllerTest.php
git commit -m "feat: add migration to delete removed block types (event_info, network_stats, connection_status)"
```

### Task 2: Update ContentBlock model — remove deleted types from SINGLETON_TYPES

**Files:**
- Modify: `app/Models/ContentBlock.php:17-23`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/ContentControllerTest.php`:

```php
public function test_singleton_types_excludes_removed_types(): void
{
    $this->assertNotContains('event_info', ContentBlock::SINGLETON_TYPES);
    $this->assertNotContains('network_stats', ContentBlock::SINGLETON_TYPES);
    $this->assertNotContains('connection_status', ContentBlock::SINGLETON_TYPES);
    $this->assertContains('connection_strip', ContentBlock::SINGLETON_TYPES);
    $this->assertContains('bandwidth', ContentBlock::SINGLETON_TYPES);
    $this->assertContains('dns_filter', ContentBlock::SINGLETON_TYPES);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_singleton_types_excludes_removed_types`
Expected: FAIL — `network_stats` and `connection_status` are still in the array

- [ ] **Step 3: Update SINGLETON_TYPES**

In `app/Models/ContentBlock.php`, replace the constant:

```php
/** @var list<string> */
public const SINGLETON_TYPES = [
    'connection_strip',
    'bandwidth',
    'dns_filter',
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=test_singleton_types_excludes_removed_types`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/ContentBlock.php tests/Feature/Admin/ContentControllerTest.php
git commit -m "feat: remove event_info, network_stats, connection_status from SINGLETON_TYPES"
```

### Task 3: Update ContentBlockFactory — remove deleted type references

**Files:**
- Modify: `database/factories/ContentBlockFactory.php:21,42-47,85-89`

- [ ] **Step 1: Update factory definition and remove dead states**

In `database/factories/ContentBlockFactory.php`:

Replace the `definition()` method's type line:
```php
'type' => fake()->randomElement(['bandwidth', 'custom_markdown']),
```

Remove the `eventInfo()` state method entirely (lines 42–47).

Remove the `networkStats()` state method entirely (lines 85–89).

- [ ] **Step 2: Run existing tests to verify nothing breaks**

Run: `php artisan test --compact --filter=ContentControllerTest`
Expected: PASS (all existing tests still work)

- [ ] **Step 3: Commit**

```bash
git add database/factories/ContentBlockFactory.php
git commit -m "refactor: remove deleted block types from ContentBlockFactory"
```

### Task 4: Update ContentBlockSeeder — remove deleted type seed data

**Files:**
- Modify: `database/seeders/ContentBlockSeeder.php`

- [ ] **Step 1: Remove event_info and network_stats blocks from seeder**

In `database/seeders/ContentBlockSeeder.php`, remove the `event_info` block (lines 24–33) and the `network_stats` block (lines 35–44) from the `$blocks` array. Update grid positions so remaining blocks fill naturally:

```php
$blocks = [
    [
        'type' => 'connection_strip',
        'title' => 'Connection Status',
        'content' => null,
        'grid_col' => 1,
        'grid_row' => 1,
        'col_span' => 3,
        'row_span' => 1,
        'is_active' => true,
        'settings' => null,
    ],
    [
        'type' => 'custom_markdown',
        'title' => 'Welcome to the LAN Party',
        'content' => "Check the schedule and make the most of your time here.\n\n**Have fun** and play fair!",
        'grid_col' => 1,
        'grid_row' => 2,
        'col_span' => 2,
        'row_span' => 1,
        'is_active' => true,
        'settings' => null,
    ],
    [
        'type' => 'bandwidth',
        'title' => 'Your Bandwidth',
        'content' => null,
        'grid_col' => 3,
        'grid_row' => 2,
        'col_span' => 1,
        'row_span' => 1,
        'is_active' => true,
        'settings' => null,
    ],
    [
        'type' => 'dns_filter',
        'title' => 'DNS Ad Blocking',
        'content' => 'Toggle DNS filtering for your connection.',
        'grid_col' => 1,
        'grid_row' => 3,
        'col_span' => 1,
        'row_span' => 1,
        'is_active' => true,
        'settings' => null,
    ],
];
```

Note: The old `event_info` seed becomes a `custom_markdown` block with markdown content.

- [ ] **Step 2: Commit**

```bash
git add database/seeders/ContentBlockSeeder.php
git commit -m "refactor: update ContentBlockSeeder to remove deleted block types"
```

### Task 5: Route consolidation — editor becomes the index route

**Files:**
- Modify: `routes/web.php:107`
- Modify: `app/Http/Controllers/Admin/ContentController.php:17-42`

- [ ] **Step 1: Write the failing test**

Update the existing test in `tests/Feature/Admin/ContentControllerTest.php`:

Replace the `test_admin_can_view_content_blocks` test:

```php
public function test_admin_can_view_content_blocks(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();
    ContentBlock::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get('/admin/content');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Content/Editor')
        ->has('blocks', 3)
        ->has('singletonTypes')
        ->has('existingTypes')
    );
}
```

Add a new test:

```php
public function test_editor_route_no_longer_exists(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->get('/admin/content/editor');

    $response->assertNotFound();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_admin_can_view_content_blocks|test_editor_route_no_longer_exists"`
Expected: FAIL — index still renders `Admin/Content/Index`, editor route still exists

- [ ] **Step 3: Update controller — merge editor into index**

In `app/Http/Controllers/Admin/ContentController.php`, replace the `index()` method and remove `editor()`:

```php
public function index(): Response
{
    $blocks = ContentBlock::orderBy('grid_row')->orderBy('grid_col')->get();

    return Inertia::render('Admin/Content/Editor', [
        'blocks' => $blocks,
        'singletonTypes' => ContentBlock::SINGLETON_TYPES,
        'existingTypes' => ContentBlock::pluck('type')->unique()->values(),
        'breadcrumbs' => [
            ['label' => 'Admin', 'href' => route('admin.home')],
            ['label' => 'Content'],
        ],
    ]);
}
```

Remove the `editor()` method entirely.

- [ ] **Step 4: Update routes — remove editor route**

In `routes/web.php`, remove the line:
```php
Route::get('/content/editor', [ContentController::class, 'editor'])->name('content.editor');
```

Keep the remaining two lines:
```php
// Content blocks
Route::put('/content/layout', [ContentController::class, 'updateLayout'])->name('content.layout.update');
Route::resource('content', ContentController::class)->except(['create', 'edit', 'show']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="test_admin_can_view_content_blocks|test_editor_route_no_longer_exists"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ContentController.php routes/web.php tests/Feature/Admin/ContentControllerTest.php
git commit -m "feat: consolidate content index and editor routes — editor becomes the index"
```

### Task 6: Delete frontend components and update registries

**Files:**
- Delete: `resources/js/Pages/Admin/Content/Index.vue`
- Delete: `resources/js/Components/Blocks/EventInfoBlock.vue`
- Delete: `resources/js/Components/Blocks/NetworkStatsBlock.vue`
- Delete: `resources/js/Components/Blocks/ConnectionStatusBlock.vue`
- Delete: `tests/js/Pages/Admin/Content/Index.spec.js`
- Modify: `resources/js/Components/BlockGrid.vue:1-18`
- Modify: `resources/js/Components/Admin/Content/EditorSidePanel.vue:10`
- Modify: `resources/js/Pages/Admin/Content/Editor.vue:134-140`

- [ ] **Step 1: Update BlockGrid.vue — remove deleted imports and registry entries**

In `resources/js/Components/BlockGrid.vue`, replace the script imports and registry:

```javascript
import ConnectionStripBlock from './Blocks/ConnectionStripBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import DnsFilterBlock from './Blocks/DnsFilterBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const blockComponents = {
    connection_strip: ConnectionStripBlock,
    bandwidth: BandwidthBlock,
    dns_filter: DnsFilterBlock,
    custom_markdown: CustomMarkdownBlock,
};
```

Update the `templateContent` function:

```javascript
function templateContent(block) {
    if (block.type === 'custom_markdown') {
        return renderTemplate(block.content, props.blockContext);
    }
    return block.content;
}
```

- [ ] **Step 2: Update EditorSidePanel.vue — remove event_info from textTypes**

In `resources/js/Components/Admin/Content/EditorSidePanel.vue`, replace line 10:

```javascript
const textTypes = ['custom_markdown'];
```

- [ ] **Step 3: Update Editor.vue — remove deleted types from blockTypeColors**

In `resources/js/Pages/Admin/Content/Editor.vue`, replace the `blockTypeColors` object:

```javascript
const blockTypeColors = {
    custom_markdown: 'rgba(34,197,94,0.3)',
    connection_strip: 'rgba(99,102,241,0.4)',
    bandwidth: 'rgba(59,130,246,0.3)',
    dns_filter: 'rgba(236,72,153,0.3)',
};
```

- [ ] **Step 4: Delete removed component files**

```bash
rm resources/js/Pages/Admin/Content/Index.vue
rm resources/js/Components/Blocks/EventInfoBlock.vue
rm resources/js/Components/Blocks/NetworkStatsBlock.vue
rm resources/js/Components/Blocks/ConnectionStatusBlock.vue
rm tests/js/Pages/Admin/Content/Index.spec.js
```

- [ ] **Step 5: Update JS test files — replace deleted type references**

In `tests/js/Components/BlockGrid.spec.js`, replace all `type: 'event_info'` with `type: 'custom_markdown'`. Update all `data-testid` assertions accordingly — change `block-event_info-wrapper` to `block-custom_markdown-wrapper`.

In `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`, change the test block's type from `'event_info'` to `'custom_markdown'`. Update the assertion in "renders block type as read-only" to check for `'custom_markdown'` instead of `'event_info'`.

In `tests/js/Pages/Admin/Content/Editor.spec.js`, change the test block's type from `'event_info'` to `'custom_markdown'`. Update `singletonTypes` to `['bandwidth', 'connection_strip', 'dns_filter']`. Update `existingTypes` to `['custom_markdown', 'bandwidth']`.

In `tests/Feature/Portal/DashboardControllerTest.php`, replace all `'type' => 'event_info'` with `'type' => 'custom_markdown'` and `'type' => 'connection_status'` with `'type' => 'connection_strip'`.

- [ ] **Step 6: Run all JS and PHP tests**

Run: `npx vitest run` and `php artisan test --compact`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: delete removed block types and update all registries and tests"
```

### Task 7: Lint and format Phase 1

- [ ] **Step 1: Run all formatters and linters**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector process --dry-run
npx eslint resources/js/ --fix
npx prettier --write resources/js/ resources/css/
```

- [ ] **Step 2: Fix any issues reported by rector or phpstan**

Run: `vendor/bin/phpstan analyse`
Fix any errors.

- [ ] **Step 3: Run full test suite**

Run: `php artisan test --compact && npx vitest run`
Expected: All PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: lint and format Phase 1 changes"
```

---

## Phase 2: Markdown Rendering

### Task 8: Install marked and dompurify

- [ ] **Step 1: Install npm dependencies**

```bash
npm install marked dompurify
```

- [ ] **Step 2: Commit**

```bash
git add package.json package-lock.json
git commit -m "deps: add marked and dompurify for markdown rendering"
```

### Task 9: Update CustomMarkdownBlock to render markdown

**Files:**
- Modify: `resources/js/Components/Blocks/CustomMarkdownBlock.vue`
- Create: `tests/js/Components/Blocks/CustomMarkdownBlock.spec.js`

- [ ] **Step 1: Write the failing test**

Create `tests/js/Components/Blocks/CustomMarkdownBlock.spec.js`:

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CustomMarkdownBlock from '@/Components/Blocks/CustomMarkdownBlock.vue';

describe('CustomMarkdownBlock', () => {
    it('renders markdown content as HTML', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '**bold text**' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).toContain('<strong>bold text</strong>');
    });

    it('renders headings from markdown', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '## Sub Heading' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).toContain('<h2');
        expect(html).toContain('Sub Heading');
    });

    it('renders lists from markdown', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '- item one\n- item two' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).toContain('<li>');
        expect(html).toContain('item one');
    });

    it('sanitizes dangerous HTML', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '<script>alert("xss")</script>hello' },
        });
        const html = wrapper.find('[data-testid="block-custom-markdown-content"]').html();
        expect(html).not.toContain('<script>');
        expect(html).toContain('hello');
    });

    it('handles empty content gracefully', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'Test', content: '' },
        });
        expect(wrapper.find('[data-testid="block-custom-markdown-content"]').exists()).toBe(true);
    });

    it('renders the title', () => {
        const wrapper = mount(CustomMarkdownBlock, {
            props: { title: 'My Title', content: 'test' },
        });
        expect(wrapper.text()).toContain('My Title');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/Components/Blocks/CustomMarkdownBlock.spec.js`
Expected: FAIL — no markdown rendering, no `data-testid="block-custom-markdown-content"`

- [ ] **Step 3: Update CustomMarkdownBlock.vue**

Replace the entire file:

```vue
<script setup>
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const props = defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
});

const renderedContent = computed(() => {
    if (!props.content) return '';
    const html = marked.parse(props.content, { async: false });
    return DOMPurify.sanitize(html);
});
</script>

<template>
    <div data-testid="block-custom-markdown">
        <h3
            class="mb-3 font-heading text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
        >
            {{ title }}
        </h3>
        <div
            data-testid="block-custom-markdown-content"
            class="prose prose-sm max-w-none text-[var(--color-text-secondary)]"
            v-html="renderedContent"
        />
    </div>
</template>
```

Note: If `@tailwindcss/typography` is not installed, replace the `prose prose-sm` classes with scoped styles for headings, lists, links, and code blocks. Check `tailwind.config.js` for existing plugins first.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/Components/Blocks/CustomMarkdownBlock.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Blocks/CustomMarkdownBlock.vue tests/js/Components/Blocks/CustomMarkdownBlock.spec.js
git commit -m "feat: render markdown in CustomMarkdownBlock using marked + DOMPurify"
```

---

## Phase 3: Settings & Templates

### Task 10: Update renderTemplate — new variable patterns

**Files:**
- Modify: `resources/js/utils/contentTemplating.js`
- Modify: `tests/js/utils/contentTemplating.spec.js`

- [ ] **Step 1: Write failing tests for new patterns**

Add to `tests/js/utils/contentTemplating.spec.js`:

```javascript
it('replaces {ipv4} with IPv4 address', () => {
    const ctx = { currentIpv4: '192.168.1.1', currentIpv6: null, macAddress: null, user: {} };
    expect(renderTemplate('IP: {ipv4}', ctx)).toBe('IP: 192.168.1.1');
});

it('replaces {ipv6} with IPv6 address', () => {
    const ctx = { currentIpv4: null, currentIpv6: 'fe80::1', macAddress: null, user: {} };
    expect(renderTemplate('IP: {ipv6}', ctx)).toBe('IP: fe80::1');
});

it('replaces {user.params.seat} with user parameter', () => {
    const ctx = { currentIpv4: null, currentIpv6: null, macAddress: null, user: { name: 'Test', params: { seat: 'A42', vlan: '100' } } };
    expect(renderTemplate('Seat: {user.params.seat}', ctx)).toBe('Seat: A42');
});

it('replaces {user.params.vlan} with user parameter', () => {
    const ctx = { currentIpv4: null, currentIpv6: null, macAddress: null, user: { params: { vlan: '100' } } };
    expect(renderTemplate('VLAN: {user.params.vlan}', ctx)).toBe('VLAN: 100');
});

it('renders empty string for missing user param', () => {
    const ctx = { currentIpv4: null, currentIpv6: null, macAddress: null, user: { params: {} } };
    expect(renderTemplate('{user.params.missing}', ctx)).toBe('');
});

it('handles user with no params object', () => {
    const ctx = { currentIpv4: null, currentIpv6: null, macAddress: null, user: { name: 'Test' } };
    expect(renderTemplate('{user.params.seat}', ctx)).toBe('');
});
```

Update existing tests to use `currentIpv4` instead of `currentIp` in the context, and add `currentIpv6` to the context:

```javascript
const context = {
    currentIpv4: '10.0.0.1',
    currentIpv6: 'fe80::1',
    macAddress: 'AA:BB:CC:DD:EE:FF',
    user: { name: 'Player', params: { seat: 'A42', team: 'Red' } },
};
```

Update existing `{ip}` tests to test `{ipv4}` instead. Move `seat` and `team` under `user.params` in existing tests.

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/utils/contentTemplating.spec.js`
Expected: FAIL — old patterns still in use

- [ ] **Step 3: Update renderTemplate**

Replace `resources/js/utils/contentTemplating.js`:

```javascript
/**
 * Replace template placeholders in content with values from block context.
 *
 * Supported placeholders:
 *   {user.params.<key>} - User parameter value
 *   {user.<key>}        - User property
 *   {ipv4}              - Client IPv4 address
 *   {ipv6}              - Client IPv6 address
 *   {mac}               - MAC address
 *
 * @param {string|null} content - Template string with placeholders
 * @param {Object} context - Block context with currentIpv4, currentIpv6, macAddress, user
 * @returns {string} Rendered content
 */
export function renderTemplate(content, context) {
    if (!content) return '';

    return content
        .replace(/\{user\.params\.([^}]+)\}/g, (_, key) => context.user?.params?.[key] ?? '')
        .replace(/\{user\.([^}]+)\}/g, (_, key) => context.user?.[key] ?? '')
        .replace(/\{ipv4\}/g, context.currentIpv4 ?? '')
        .replace(/\{ipv6\}/g, context.currentIpv6 ?? '')
        .replace(/\{mac\}/g, context.macAddress ?? '');
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/utils/contentTemplating.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/utils/contentTemplating.js tests/js/utils/contentTemplating.spec.js
git commit -m "feat: update renderTemplate with {ipv4}, {ipv6}, {user.params.*} patterns"
```

### Task 11: Create templateVariables.js — shared variable definitions

**Files:**
- Create: `resources/js/utils/templateVariables.js`
- Create: `tests/js/utils/templateVariables.spec.js`

- [ ] **Step 1: Write the test**

Create `tests/js/utils/templateVariables.spec.js`:

```javascript
import { describe, it, expect } from 'vitest';
import { TEMPLATE_VARIABLES, TEMPLATE_VARIABLE_GROUPS } from '@/utils/templateVariables.js';

describe('templateVariables', () => {
    it('exports a non-empty array of variables', () => {
        expect(Array.isArray(TEMPLATE_VARIABLES)).toBe(true);
        expect(TEMPLATE_VARIABLES.length).toBeGreaterThan(0);
    });

    it('each variable has key, label, and group', () => {
        for (const v of TEMPLATE_VARIABLES) {
            expect(v).toHaveProperty('key');
            expect(v).toHaveProperty('label');
            expect(v).toHaveProperty('group');
        }
    });

    it('includes {ipv4}, {ipv6}, {mac}', () => {
        const keys = TEMPLATE_VARIABLES.map((v) => v.key);
        expect(keys).toContain('{ipv4}');
        expect(keys).toContain('{ipv6}');
        expect(keys).toContain('{mac}');
    });

    it('includes {user.name} and {user.params.*}', () => {
        const keys = TEMPLATE_VARIABLES.map((v) => v.key);
        expect(keys).toContain('{user.name}');
        expect(keys).toContain('{user.params.*}');
    });

    it('exports groups as an array of group names', () => {
        expect(Array.isArray(TEMPLATE_VARIABLE_GROUPS)).toBe(true);
        expect(TEMPLATE_VARIABLE_GROUPS).toContain('Connection');
        expect(TEMPLATE_VARIABLE_GROUPS).toContain('User');
        expect(TEMPLATE_VARIABLE_GROUPS).toContain('User Parameters');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/utils/templateVariables.spec.js`
Expected: FAIL — module doesn't exist

- [ ] **Step 3: Create templateVariables.js**

Create `resources/js/utils/templateVariables.js`:

```javascript
export const TEMPLATE_VARIABLES = [
    { key: '{ipv4}', label: 'Client IPv4 address', group: 'Connection' },
    { key: '{ipv6}', label: 'Client IPv6 address', group: 'Connection' },
    { key: '{mac}', label: 'Client MAC address', group: 'Connection' },
    { key: '{user.name}', label: "User's display name", group: 'User' },
    { key: '{user.*}', label: 'Any user property', group: 'User' },
    { key: '{user.params.*}', label: 'User parameter by key (e.g. {user.params.seat})', group: 'User Parameters' },
];

export const TEMPLATE_VARIABLE_GROUPS = [...new Set(TEMPLATE_VARIABLES.map((v) => v.group))];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/utils/templateVariables.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/utils/templateVariables.js tests/js/utils/templateVariables.spec.js
git commit -m "feat: add shared template variable definitions"
```

### Task 12: ConnectionStripBlock — settings-driven fields

**Files:**
- Modify: `resources/js/Components/Blocks/ConnectionStripBlock.vue`
- Create: `tests/js/Components/Blocks/ConnectionStripBlock.spec.js`

- [ ] **Step 1: Write failing tests**

Create `tests/js/Components/Blocks/ConnectionStripBlock.spec.js`:

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectionStripBlock from '@/Components/Blocks/ConnectionStripBlock.vue';

describe('ConnectionStripBlock', () => {
    const defaultContext = {
        currentIpv4: '10.0.0.1',
        currentIpv6: 'fe80::1',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        ipAllowed: true,
        user: { name: 'Player', params: { seat: 'A42' } },
    };

    it('renders default fields when settings.fields is empty', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { settings: {}, blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('IPv4');
        expect(wrapper.text()).toContain('10.0.0.1');
        expect(wrapper.text()).toContain('IPv6');
        expect(wrapper.text()).toContain('MAC');
    });

    it('renders custom fields from settings.fields', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {
                    fields: [
                        { label: 'Seat', value: '{user.params.seat}' },
                        { label: 'IP', value: '{ipv4}' },
                    ],
                },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.text()).toContain('Seat');
        expect(wrapper.text()).toContain('A42');
        expect(wrapper.text()).toContain('IP');
        expect(wrapper.text()).toContain('10.0.0.1');
    });

    it('renders template variables in field values', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {
                    fields: [{ label: 'MAC', value: '{mac}' }],
                },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.text()).toContain('AA:BB:CC:DD:EE:FF');
    });

    it('renders em dash for empty template result', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {
                    fields: [{ label: 'Missing', value: '{user.params.unknown}' }],
                },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.text()).toContain('\u2014');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Components/Blocks/ConnectionStripBlock.spec.js`
Expected: FAIL — no settings-driven rendering

- [ ] **Step 3: Update ConnectionStripBlock.vue**

Replace the entire file:

```vue
<script setup>
import { computed } from 'vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const props = defineProps({
    title: { type: String, default: 'Connection Status' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const DEFAULT_FIELDS = [
    { label: 'IPv4', value: '{ipv4}' },
    { label: 'IPv6', value: '{ipv6}' },
    { label: 'MAC Address', value: '{mac}' },
];

const fields = computed(() => {
    const configuredFields = props.settings?.fields;
    if (Array.isArray(configuredFields) && configuredFields.length > 0) {
        return configuredFields;
    }
    return DEFAULT_FIELDS;
});

function resolveValue(template) {
    const result = renderTemplate(template, props.blockContext);
    return result || '\u2014';
}
</script>

<template>
    <div data-testid="block-connection-strip">
        <div class="flex items-center">
            <div
                v-for="(field, index) in fields"
                :key="index"
                class="flex flex-1 flex-col gap-0.5"
                :class="index < fields.length - 1 ? 'border-r border-[var(--color-border)] pr-5' : ''"
                :style="index > 0 ? 'padding-left: 1.25rem' : ''"
                :data-testid="'connection-strip-field-' + index"
            >
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                    {{ field.label }}
                </span>
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">
                    {{ resolveValue(field.value) }}
                </span>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Components/Blocks/ConnectionStripBlock.spec.js`
Expected: PASS

- [ ] **Step 5: Update BlockGrid.vue blockContext references**

If `BlockGrid.vue` passes `currentIp` to block context, update references to use `currentIpv4`/`currentIpv6`. Check the portal Dashboard controller that provides `blockContext` — if it sends `currentIp`, update the key name there too. Also update `tests/js/Components/BlockGrid.spec.js` context to use `currentIpv4`.

- [ ] **Step 6: Run all tests**

Run: `npx vitest run`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Blocks/ConnectionStripBlock.vue tests/js/Components/Blocks/ConnectionStripBlock.spec.js resources/js/Components/BlockGrid.vue tests/js/Components/BlockGrid.spec.js
git commit -m "feat: connection strip renders fields from settings.fields with template variable support"
```

### Task 13: DnsFilterBlock — settings-driven title/description

**Files:**
- Modify: `resources/js/Components/Blocks/DnsFilterBlock.vue`
- Create: `tests/js/Components/Blocks/DnsFilterBlock.spec.js`

- [ ] **Step 1: Write failing tests**

Create `tests/js/Components/Blocks/DnsFilterBlock.spec.js`:

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import DnsFilterBlock from '@/Components/Blocks/DnsFilterBlock.vue';

vi.stubGlobal(
    'route',
    vi.fn(() => '/mock-route'),
);
vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ enabled: true }) })),
);

describe('DnsFilterBlock', () => {
    it('uses settings.title when available', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'Prop Title',
                content: 'Prop Content',
                settings: { title: 'Settings Title', description: 'Settings Desc' },
            },
        });
        expect(wrapper.text()).toContain('Settings Title');
    });

    it('uses settings.description when available', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'Prop Title',
                content: 'Prop Content',
                settings: { title: 'T', description: 'Custom description text' },
            },
        });
        expect(wrapper.text()).toContain('Custom description text');
    });

    it('falls back to title prop when settings.title is empty', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'Fallback Title',
                content: 'Fallback Content',
                settings: {},
            },
        });
        expect(wrapper.text()).toContain('Fallback Title');
    });

    it('falls back to content prop when settings.description is empty', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'T',
                content: 'Fallback description',
                settings: {},
            },
        });
        expect(wrapper.text()).toContain('Fallback description');
    });

    it('renders the toggle button', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { settings: {} },
        });
        expect(wrapper.find('[data-testid="dns-filter-toggle"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Components/Blocks/DnsFilterBlock.spec.js`
Expected: FAIL — settings not used for title/description

- [ ] **Step 3: Update DnsFilterBlock.vue**

In `DnsFilterBlock.vue`, add computed properties for resolved title and description. In the `<script setup>`:

```javascript
import { ref, computed } from 'vue';

const props = defineProps({
    title: { type: String, default: 'DNS Ad Blocking' },
    content: { type: String, default: 'Toggle DNS filtering for your connection.' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const displayTitle = computed(() => props.settings?.title || props.title);
const displayDescription = computed(() => props.settings?.description || props.content);
```

In the template, replace `{{ title }}` with `{{ displayTitle }}` and `{{ content }}` with `{{ displayDescription }}`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Components/Blocks/DnsFilterBlock.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Blocks/DnsFilterBlock.vue tests/js/Components/Blocks/DnsFilterBlock.spec.js
git commit -m "feat: DnsFilterBlock uses settings.title and settings.description with prop fallback"
```

### Task 14: EditorSidePanel — type-specific settings forms

**Files:**
- Modify: `resources/js/Components/Admin/Content/EditorSidePanel.vue`
- Modify: `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`

- [ ] **Step 1: Write failing tests**

Add to `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`:

```javascript
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
        props: { block: { ...block, type: 'custom_markdown' } },
    });
    expect(wrapper.find('[data-testid="panel-template-variables"]').exists()).toBe(true);
});

it('does not show template variable reference for bandwidth blocks', () => {
    const wrapper = mount(EditorSidePanel, {
        props: { block: { ...block, type: 'bandwidth' } },
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Components/Admin/Content/EditorSidePanel.spec.js`
Expected: FAIL — no settings forms exist

- [ ] **Step 3: Update EditorSidePanel.vue**

This is a significant update to the component. Key changes:

**Script section** — add settings-related refs and logic:

```javascript
import { ref, watch, computed } from 'vue';
import { TEMPLATE_VARIABLES, TEMPLATE_VARIABLE_GROUPS } from '@/utils/templateVariables.js';

// ... existing props/emit ...

const textTypes = ['custom_markdown'];
const templateSupportedTypes = ['custom_markdown', 'connection_strip'];

const title = ref(props.block.title);
const content = ref(props.block.content ?? '');
const isActive = ref(props.block.is_active);

// Connection strip fields
const fields = ref(
    props.block.type === 'connection_strip'
        ? JSON.parse(JSON.stringify(props.block.settings?.fields ?? []))
        : [],
);

// DNS filter settings
const settingsTitle = ref(props.block.settings?.title ?? '');
const settingsDescription = ref(props.block.settings?.description ?? '');

// Template variables
const variablesExpanded = ref(false);

const showTemplateVariables = computed(() => templateSupportedTypes.includes(props.block.type));

function addField() {
    fields.value.push({ label: '', value: '' });
}

function removeField(index) {
    fields.value.splice(index, 1);
}

function buildSettings() {
    if (props.block.type === 'connection_strip') {
        return { fields: fields.value };
    }
    if (props.block.type === 'dns_filter') {
        return { title: settingsTitle.value, description: settingsDescription.value };
    }
    return props.block.settings;
}

function save() {
    emit('save', {
        id: props.block.id,
        title: title.value,
        content: content.value,
        is_active: isActive.value,
        settings: buildSettings(),
    });
}
```

Update the `watch` to also sync settings refs when block changes.

**Template section** — add type-specific settings sections between the Content textarea and the Active toggle. See the spec for exact UI: repeatable field rows with +/trash for connection_strip, title/description inputs for dns_filter, collapsible variable reference for template-supported types.

Remove the Column Span and Row Span sections entirely (change 9).

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Components/Admin/Content/EditorSidePanel.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/Content/EditorSidePanel.vue tests/js/Components/Admin/Content/EditorSidePanel.spec.js
git commit -m "feat: add type-specific settings forms and template variable reference to side panel"
```

### Task 15: Lint and format Phase 3

- [ ] **Step 1: Run all formatters and linters**

```bash
vendor/bin/pint --dirty --format agent
npx eslint resources/js/ --fix
npx prettier --write resources/js/ resources/css/
```

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact && npx vitest run`
Expected: All PASS

- [ ] **Step 3: Commit if changes**

```bash
git add -A
git commit -m "chore: lint and format Phase 3 changes"
```

---

## Phase 4: Grid Interactions

### Task 16: useGridEditor — computeDisplacement function

**Files:**
- Modify: `resources/js/composables/useGridEditor.js`
- Modify: `tests/js/composables/useGridEditor.spec.js`

- [ ] **Step 1: Write failing tests**

Add to `tests/js/composables/useGridEditor.spec.js`:

```javascript
describe('computeDisplacement', () => {
    it('returns empty map when target is unoccupied', () => {
        const blocks = makeBlocks([[1, 1], [3, 1]]);
        const { computeDisplacement } = useGridEditor(blocks);
        const result = computeDisplacement(1, 2, 1, 1, 1);
        expect(result).toEqual({});
    });

    it('pushes overlapped block down', () => {
        const blocks = makeBlocks([[1, 1], [2, 1]]);
        const { computeDisplacement } = useGridEditor(blocks);
        // Move block 1 to col 2 row 1 — overlaps block 2
        const result = computeDisplacement(1, 2, 1, 1, 1);
        expect(result[2]).toBe(2); // block 2 pushed to row 2
    });

    it('cascades displacement when pushed block overlaps another', () => {
        const blocks = makeBlocks([[1, 1], [2, 1], [2, 2]]);
        const { computeDisplacement } = useGridEditor(blocks);
        // Move block 1 to col 2 row 1 — pushes block 2 to row 2, which pushes block 3 to row 3
        const result = computeDisplacement(1, 2, 1, 1, 1);
        expect(result[2]).toBe(2);
        expect(result[3]).toBe(3);
    });

    it('handles multi-span block displacement', () => {
        const blocks = makeBlocks([[1, 1, 2, 1], [1, 2]]);
        const { computeDisplacement } = useGridEditor(blocks);
        // Move block 1 (2x1) to row 2 — overlaps block 2 at (1,2)
        const result = computeDisplacement(1, 1, 2, 2, 1);
        expect(result[2]).toBe(3); // block 2 pushed to row 3
    });

    it('does not displace blocks that are not overlapped', () => {
        const blocks = makeBlocks([[1, 1], [3, 3]]);
        const { computeDisplacement } = useGridEditor(blocks);
        const result = computeDisplacement(1, 1, 2, 1, 1);
        expect(result).toEqual({}); // block 2 at (3,3) is not affected
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/composables/useGridEditor.spec.js`
Expected: FAIL — `computeDisplacement` doesn't exist

- [ ] **Step 3: Implement computeDisplacement**

Add to `useGridEditor.js` inside the composable function, before the return statement:

```javascript
function computeDisplacement(draggedId, targetCol, targetRow, colSpan, rowSpan) {
    const displacement = {};

    // Build a working copy of positions
    const positions = {};
    for (const block of blocks.value) {
        if (block.id === draggedId) {
            positions[block.id] = { col: targetCol, row: targetRow, colSpan, rowSpan };
        } else {
            positions[block.id] = {
                col: block.grid_col,
                row: block.grid_row,
                colSpan: block.col_span,
                rowSpan: block.row_span,
            };
        }
    }

    // Iteratively resolve overlaps
    let changed = true;
    while (changed) {
        changed = false;
        for (const block of blocks.value) {
            if (block.id === draggedId) continue;
            const pos = positions[block.id];
            // Check if this block overlaps with the dragged block or any already-displaced block
            for (const otherId of Object.keys(positions).map(Number)) {
                if (otherId === block.id) continue;
                const other = positions[otherId];
                if (
                    pos.col < other.col + other.colSpan &&
                    pos.col + pos.colSpan > other.col &&
                    pos.row < other.row + other.rowSpan &&
                    pos.row + pos.rowSpan > other.row
                ) {
                    // Push this block below the overlapping block
                    const newRow = other.row + other.rowSpan;
                    if (newRow !== pos.row) {
                        positions[block.id] = { ...pos, row: newRow };
                        displacement[block.id] = newRow;
                        changed = true;
                    }
                }
            }
        }
    }

    return displacement;
}
```

Add `computeDisplacement` to the return object.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/composables/useGridEditor.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/composables/useGridEditor.js tests/js/composables/useGridEditor.spec.js
git commit -m "feat: add computeDisplacement to useGridEditor for push-down reflow"
```

### Task 17: Editor.vue — drag reflow with preview and cancel

**Files:**
- Modify: `resources/js/Pages/Admin/Content/Editor.vue`
- Modify: `tests/js/Pages/Admin/Content/Editor.spec.js`

- [ ] **Step 1: Write failing tests**

Add to `tests/js/Pages/Admin/Content/Editor.spec.js`:

```javascript
it('does not show resize handle on blocks (no resize handles without Phase 4 Task 18)', () => {
    // Placeholder — will be updated in Task 18
});

it('restores positions on drag cancel (Escape key)', async () => {
    const wrapper = mount(Editor, { props: defaultProps });
    const block1 = wrapper.find('[data-testid="editor-block-1"]');
    // Record original position
    const originalStyle = block1.attributes('style');
    // Start drag
    await block1.trigger('dragstart', { dataTransfer: { effectAllowed: '' } });
    // Press Escape
    await wrapper.trigger('keydown', { key: 'Escape' });
    // Position should be restored
    expect(block1.attributes('style')).toBe(originalStyle);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Pages/Admin/Content/Editor.spec.js`
Expected: FAIL

- [ ] **Step 3: Implement drag reflow in Editor.vue**

Key changes to `Editor.vue`:

1. Import `computeDisplacement` from `useGridEditor`:
```javascript
const { totalRows, canPlace, moveBlock, computeDisplacement } = useGridEditor(localBlocks);
```

2. Add snapshot/preview state:
```javascript
const positionSnapshot = ref(null);
const previewDisplacement = ref({});
```

3. On `onDragStart`, snapshot all positions:
```javascript
function onDragStart(block, event) {
    dragging.value = block.id;
    event.dataTransfer.effectAllowed = 'move';
    positionSnapshot.value = localBlocks.value.map((b) => ({
        id: b.id,
        grid_col: b.grid_col,
        grid_row: b.grid_row,
    }));
}
```

4. On `onDragOver`, compute displacement preview:
```javascript
function onDragOver(col, row, event) {
    event.preventDefault();
    if (!dragging.value) return;
    const block = getBlock(dragging.value);
    dragOver.value = `${col},${row}`;
    event.dataTransfer.dropEffect = 'move';
    previewDisplacement.value = computeDisplacement(dragging.value, col, row, block.col_span, block.row_span);
}
```

5. On `onDrop`, apply displacement:
```javascript
function onDrop(col, row) {
    if (!dragging.value) return;
    const block = getBlock(dragging.value);
    const displacement = computeDisplacement(dragging.value, col, row, block.col_span, block.row_span);
    moveBlock(dragging.value, col, row);
    for (const [id, newRow] of Object.entries(displacement)) {
        const displaced = getBlock(Number(id));
        if (displaced) displaced.grid_row = newRow;
    }
    hasChanges.value = true;
    dragging.value = null;
    dragOver.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
}
```

6. Add cancel handler (Escape key):
```javascript
function cancelDrag() {
    if (positionSnapshot.value) {
        for (const snap of positionSnapshot.value) {
            const block = getBlock(snap.id);
            if (block) {
                block.grid_col = snap.grid_col;
                block.grid_row = snap.grid_row;
            }
        }
    }
    dragging.value = null;
    dragOver.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
}
```

7. Add keydown listener in template on the grid container:
```html
@keydown.escape="cancelDrag"
```

8. Update `blockStyle` to apply preview displacement:
```javascript
function blockStyle(block) {
    const row = previewDisplacement.value[block.id] ?? block.grid_row;
    return {
        gridColumn: `${block.grid_col} / span ${block.col_span}`,
        gridRow: `${row} / span ${block.row_span}`,
        transition: dragging.value ? 'grid-row 200ms ease' : 'none',
    };
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Pages/Admin/Content/Editor.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Content/Editor.vue tests/js/Pages/Admin/Content/Editor.spec.js
git commit -m "feat: implement drag reflow with push-down preview and Escape to cancel"
```

### Task 18: Editor.vue — resize drag handles

**Files:**
- Modify: `resources/js/Pages/Admin/Content/Editor.vue`
- Modify: `tests/js/Pages/Admin/Content/Editor.spec.js`

- [ ] **Step 1: Write failing tests**

Add to `tests/js/Pages/Admin/Content/Editor.spec.js`:

```javascript
it('renders a resize handle on each block', () => {
    const wrapper = mount(Editor, { props: defaultProps });
    const handle = wrapper.find('[data-testid="resize-handle-1"]');
    expect(handle.exists()).toBe(true);
});

it('does not show col_span or row_span controls in side panel', async () => {
    const wrapper = mount(Editor, { props: defaultProps });
    await wrapper.find('[data-testid="editor-block-1"]').trigger('click');
    expect(wrapper.find('[data-testid="panel-col-span"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="panel-row-span"]').exists()).toBe(false);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Pages/Admin/Content/Editor.spec.js`
Expected: FAIL — no resize handles exist

- [ ] **Step 3: Add resize handles to Editor.vue**

Inside each block `div` in the template, add a resize handle element:

```html
<div
    :data-testid="'resize-handle-' + block.id"
    class="absolute right-0 bottom-0 h-4 w-4 cursor-se-resize"
    @mousedown.stop="onResizeStart(block, $event)"
>
    <svg class="h-4 w-4 text-[var(--color-text-muted)]/40" viewBox="0 0 16 16" fill="currentColor">
        <path d="M14 14H10V12H12V10H14V14ZM14 8H12V6H14V8Z" />
    </svg>
</div>
```

Add resize logic in the script:

```javascript
const resizing = ref(null);
const resizeStartPos = ref(null);

function onResizeStart(block, event) {
    event.preventDefault();
    resizing.value = block.id;
    resizeStartPos.value = { x: event.clientX, y: event.clientY, colSpan: block.col_span, rowSpan: block.row_span };
    positionSnapshot.value = localBlocks.value.map((b) => ({
        id: b.id,
        grid_col: b.grid_col,
        grid_row: b.grid_row,
        col_span: b.col_span,
        row_span: b.row_span,
    }));
    document.addEventListener('mousemove', onResizeMove);
    document.addEventListener('mouseup', onResizeEnd);
}

function onResizeMove(event) {
    if (!resizing.value) return;
    const block = getBlock(resizing.value);
    if (!block) return;

    const gridEl = document.querySelector('[data-testid="editor-grid"]');
    const cellWidth = gridEl.clientWidth / 3;
    const cellHeight = 80; // minmax(80px, auto) base

    const dx = event.clientX - resizeStartPos.value.x;
    const dy = event.clientY - resizeStartPos.value.y;

    const newColSpan = Math.max(1, Math.min(3 - block.grid_col + 1, resizeStartPos.value.colSpan + Math.round(dx / cellWidth)));
    const newRowSpan = Math.max(1, resizeStartPos.value.rowSpan + Math.round(dy / cellHeight));

    if (newColSpan !== block.col_span || newRowSpan !== block.row_span) {
        block.col_span = newColSpan;
        block.row_span = newRowSpan;
        previewDisplacement.value = computeDisplacement(
            block.id,
            block.grid_col,
            block.grid_row,
            newColSpan,
            newRowSpan,
        );
    }
}

function onResizeEnd() {
    if (!resizing.value) return;
    const block = getBlock(resizing.value);
    if (block) {
        // Apply displacement
        for (const [id, newRow] of Object.entries(previewDisplacement.value)) {
            const displaced = getBlock(Number(id));
            if (displaced) displaced.grid_row = newRow;
        }
        hasChanges.value = true;
    }
    resizing.value = null;
    resizeStartPos.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
    document.removeEventListener('mousemove', onResizeMove);
    document.removeEventListener('mouseup', onResizeEnd);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Pages/Admin/Content/Editor.spec.js`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Content/Editor.vue tests/js/Pages/Admin/Content/Editor.spec.js
git commit -m "feat: add resize drag handles with push-down reflow support"
```

### Task 19: Lint, format, and final test run

- [ ] **Step 1: Run all formatters and linters**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
npx eslint resources/js/ --fix
npx prettier --write resources/js/ resources/css/
```

- [ ] **Step 2: Fix any issues**

Address any linting, formatting, or static analysis issues.

- [ ] **Step 3: Run full test suite**

```bash
php artisan test --compact
npx vitest run
```

Expected: All PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: final lint, format, and quality pass for Dashboard Editor V2"
```
