# Settings & Theme Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic integrations form with a table-and-click-through architecture, add capability assignment, connection test logging, grouped settings nav, and fix the theme system.

**Architecture:** New `CapabilityAssignment` and `ConnectionTestLog` Eloquent models store capability ownership and test history. `SettingsController` gets new endpoints for per-service show/update and capability toggles. The Vue frontend replaces the single `Integrations.vue` form with a read-only table page + per-service config pages. `SettingsNav.vue` becomes a grouped sidebar. Theme validation is fixed and live preview is added.

**Tech Stack:** Laravel 12 (PHP 8.5), Vue 3 + Inertia.js, Tailwind CSS, PHPUnit, Vitest

**Design Spec:** `docs/superpowers/specs/2026-04-16-admin-ui-redesign-design.md` (Sections 1 and 6)

---

## File Structure

### New Files (Create)

| File | Responsibility |
|------|---------------|
| `database/migrations/2026_04_16_000001_create_capability_assignments_table.php` | Migration: capability → integration mapping |
| `database/migrations/2026_04_16_000002_create_connection_test_logs_table.php` | Migration: connection test history |
| `app/Models/CapabilityAssignment.php` | Eloquent model for capability assignments |
| `app/Models/ConnectionTestLog.php` | Eloquent model for test connection logs |
| `database/factories/CapabilityAssignmentFactory.php` | Factory |
| `database/factories/ConnectionTestLogFactory.php` | Factory |
| `app/Http/Controllers/Admin/IntegrationController.php` | Per-service show/update + capability toggle endpoints |
| `resources/js/Components/UI/CapabilityTag.vue` | Capability pill with active/inactive state |
| `resources/js/Pages/Admin/Settings/IntegrationShow.vue` | Per-service config page |
| `tests/Unit/Models/CapabilityAssignmentTest.php` | Unit tests for model |
| `tests/Unit/Models/ConnectionTestLogTest.php` | Unit tests for model |
| `tests/Feature/Admin/IntegrationControllerTest.php` | Feature tests for new controller |
| `tests/JavaScript/Components/CapabilityTag.test.js` | Vitest component test |
| `tests/JavaScript/Components/SettingsNav.test.js` | Vitest component test |
| `tests/JavaScript/Pages/IntegrationShow.test.js` | Vitest page test |

### Modified Files

| File | Changes |
|------|---------|
| `app/Http/Controllers/Admin/SettingsController.php` | Refactor `integrations()` to return table data; add `default` to theme validation |
| `app/Http/Controllers/Admin/TestConnectionController.php` | Add `ConnectionTestLog` recording after each test |
| `resources/js/Components/Admin/SettingsNav.vue` | Grouped sidebar with section headers |
| `resources/js/Pages/Admin/Settings/Integrations.vue` | Replace monolithic form with read-only table |
| `resources/js/Pages/Admin/Settings/Theme.vue` | Add live preview on theme selection |
| `resources/js/composables/useTheme.js` | Add `default` to `VALID_THEMES`, add `previewTheme`/`cancelPreview` |
| `routes/web.php` | Add routes for integration show/update, capability toggle, health log |
| `tests/Feature/Admin/SettingsControllerTest.php` | Update tests for refactored integrations endpoint + theme fix |

---

## Integration Capability Map (Reference)

Used throughout the plan — this is the canonical mapping:

```php
public const INTEGRATION_CAPABILITIES = [
    'borealis' => ['authentication', 'sso', 'user-info'],
    'opnsense' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
    'librenms' => ['ip-to-mac', 'mac-to-port', 'port-bandwidth', 'device-list'],
    'ntopng' => ['user-bandwidth', 'top-talkers', 'aggregate-stats'],
    'pihole' => ['dns-filtering', 'dhcp', 'ip-to-mac'],
];
```

---

## Task 1: CapabilityAssignment Model + Migration

**Files:**
- Create: `database/migrations/2026_04_16_000001_create_capability_assignments_table.php`
- Create: `app/Models/CapabilityAssignment.php`
- Create: `database/factories/CapabilityAssignmentFactory.php`
- Test: `tests/Unit/Models/CapabilityAssignmentTest.php`

- [ ] **Step 1: Write failing test for CapabilityAssignment model**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\CapabilityAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilityAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_capability_assignment(): void
    {
        $assignment = CapabilityAssignment::create([
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);

        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);
    }

    public function test_capability_is_unique(): void
    {
        CapabilityAssignment::create([
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        CapabilityAssignment::create([
            'capability' => 'dhcp',
            'integration' => 'pihole',
        ]);
    }

    public function test_assign_method_creates_or_updates(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
        ]);

        CapabilityAssignment::assign('dhcp', 'pihole');
        $this->assertDatabaseHas('capability_assignments', [
            'capability' => 'dhcp',
            'integration' => 'pihole',
        ]);
        $this->assertDatabaseCount('capability_assignments', 1);
    }

    public function test_unassign_removes_assignment(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');
        CapabilityAssignment::unassign('dhcp');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability' => 'dhcp',
        ]);
    }

    public function test_is_active_provider_returns_true_for_assigned(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');

        $this->assertTrue(CapabilityAssignment::isActiveProvider('opnsense', 'dhcp'));
        $this->assertFalse(CapabilityAssignment::isActiveProvider('pihole', 'dhcp'));
    }

    public function test_get_assignments_for_integration(): void
    {
        CapabilityAssignment::assign('dhcp', 'opnsense');
        CapabilityAssignment::assign('firewall', 'opnsense');
        CapabilityAssignment::assign('dns-filtering', 'pihole');

        $result = CapabilityAssignment::getForIntegration('opnsense');
        $this->assertEquals(['dhcp', 'firewall'], $result->toArray());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=CapabilityAssignmentTest`
Expected: FAIL (table doesn't exist)

- [ ] **Step 3: Create migration**

Run: `php artisan make:migration create_capability_assignments_table --no-interaction`

Then replace contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('capability')->unique();
            $table->string('integration');
            $table->timestamps();

            $table->index('integration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_assignments');
    }
};
```

- [ ] **Step 4: Create model**

Run: `php artisan make:class App/Models/CapabilityAssignment --no-interaction`

Replace contents with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CapabilityAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['capability', 'integration'];

    public static function assign(string $capability, string $integration): self
    {
        return static::updateOrCreate(
            ['capability' => $capability],
            ['integration' => $integration],
        );
    }

    public static function unassign(string $capability): void
    {
        static::where('capability', $capability)->delete();
    }

    public static function isActiveProvider(string $integration, string $capability): bool
    {
        return static::where('capability', $capability)
            ->where('integration', $integration)
            ->exists();
    }

    /**
     * @return Collection<int, string>
     */
    public static function getForIntegration(string $integration): Collection
    {
        return static::where('integration', $integration)
            ->pluck('capability');
    }
}
```

- [ ] **Step 5: Create factory**

Run: `php artisan make:factory CapabilityAssignmentFactory --model=CapabilityAssignment --no-interaction`

Replace `definition()` with:

```php
public function definition(): array
{
    return [
        'capability' => fake()->unique()->randomElement([
            'dhcp', 'dns-filtering', 'ip-to-mac', 'firewall',
            'captive-portal', 'rate-limiting', 'user-bandwidth',
        ]),
        'integration' => fake()->randomElement([
            'opnsense', 'librenms', 'ntopng', 'pihole', 'borealis',
        ]),
    ];
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=CapabilityAssignmentTest`
Expected: All 6 tests PASS

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add CapabilityAssignment model and migration

Stores which integration is the active provider for each capability.
Includes assign/unassign/isActiveProvider/getForIntegration helpers.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 2: ConnectionTestLog Model + Migration

**Files:**
- Create: `database/migrations/2026_04_16_000002_create_connection_test_logs_table.php`
- Create: `app/Models/ConnectionTestLog.php`
- Create: `database/factories/ConnectionTestLogFactory.php`
- Test: `tests/Unit/Models/ConnectionTestLogTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\ConnectionTestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionTestLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_log_entry(): void
    {
        $log = ConnectionTestLog::create([
            'integration' => 'opnsense',
            'success' => true,
            'message' => 'Connected successfully',
        ]);

        $this->assertDatabaseHas('connection_test_logs', [
            'integration' => 'opnsense',
            'success' => true,
        ]);
    }

    public function test_can_record_failure(): void
    {
        $log = ConnectionTestLog::record('librenms', false, 'Connection timed out');

        $this->assertDatabaseHas('connection_test_logs', [
            'integration' => 'librenms',
            'success' => false,
            'message' => 'Connection timed out',
        ]);
    }

    public function test_get_recent_for_integration(): void
    {
        ConnectionTestLog::record('opnsense', true, 'OK');
        ConnectionTestLog::record('opnsense', false, 'Timeout');
        ConnectionTestLog::record('librenms', true, 'OK');

        $logs = ConnectionTestLog::recentFor('opnsense', 10);
        $this->assertCount(2, $logs);
        $this->assertEquals('Timeout', $logs->first()->message);
    }

    public function test_latest_status_for_integration(): void
    {
        ConnectionTestLog::record('opnsense', true, 'OK');
        ConnectionTestLog::record('opnsense', false, 'Fail');

        $latest = ConnectionTestLog::latestFor('opnsense');
        $this->assertNotNull($latest);
        $this->assertFalse($latest->success);
    }

    public function test_latest_status_returns_null_when_no_logs(): void
    {
        $latest = ConnectionTestLog::latestFor('opnsense');
        $this->assertNull($latest);
    }

    public function test_success_is_cast_to_boolean(): void
    {
        $log = ConnectionTestLog::record('opnsense', true, 'OK');
        $log->refresh();
        $this->assertIsBool($log->success);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ConnectionTestLogTest`
Expected: FAIL

- [ ] **Step 3: Create migration**

Run: `php artisan make:migration create_connection_test_logs_table --no-interaction`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_test_logs', function (Blueprint $table) {
            $table->id();
            $table->string('integration')->index();
            $table->boolean('success');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_test_logs');
    }
};
```

- [ ] **Step 4: Create model**

Run: `php artisan make:class App/Models/ConnectionTestLog --no-interaction`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConnectionTestLog extends Model
{
    use HasFactory;

    protected $fillable = ['integration', 'success', 'message'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
        ];
    }

    public static function record(string $integration, bool $success, string $message): self
    {
        return static::create([
            'integration' => $integration,
            'success' => $success,
            'message' => $message,
        ]);
    }

    /**
     * @return Collection<int, self>
     */
    public static function recentFor(string $integration, int $limit = 10): Collection
    {
        return static::where('integration', $integration)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public static function latestFor(string $integration): ?self
    {
        return static::where('integration', $integration)
            ->orderByDesc('created_at')
            ->first();
    }
}
```

- [ ] **Step 5: Create factory**

Run: `php artisan make:factory ConnectionTestLogFactory --model=ConnectionTestLog --no-interaction`

```php
public function definition(): array
{
    return [
        'integration' => fake()->randomElement(['opnsense', 'librenms', 'ntopng', 'pihole']),
        'success' => fake()->boolean(80),
        'message' => fake()->sentence(),
    ];
}
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter=ConnectionTestLogTest`
Expected: All 6 tests PASS

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add ConnectionTestLog model and migration

Records connection test history per integration with success/failure,
message, and timestamps. Includes record/recentFor/latestFor helpers.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 3: Theme Validation Fix

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingsController.php`
- Modify: `resources/js/composables/useTheme.js`
- Modify: `tests/Feature/Admin/SettingsControllerTest.php`

- [ ] **Step 1: Write failing test — "default" theme should be accepted**

Add to `tests/Feature/Admin/SettingsControllerTest.php`:

```php
public function test_admin_can_set_default_theme(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/theme', [
        'theme_name' => 'default',
        'theme_mode' => 'dark',
    ]);

    $response->assertRedirect();
    $this->assertEquals('default', Setting::get('theme.name'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_admin_can_set_default_theme`
Expected: FAIL (validation rejects "default")

- [ ] **Step 3: Fix validation in SettingsController**

In `app/Http/Controllers/Admin/SettingsController.php`, change line in `updateTheme()`:

Old:
```php
'theme_name' => 'required|string|in:cool-neon,warm-neon,matrix,amber-glow',
```

New:
```php
'theme_name' => 'required|string|in:default,cool-neon,warm-neon,matrix,amber-glow',
```

- [ ] **Step 4: Add "default" to useTheme composable VALID_THEMES**

In `resources/js/composables/useTheme.js`, change:

Old:
```javascript
const VALID_THEMES = ['cool-neon', 'warm-neon', 'matrix', 'amber-glow'];
```

New:
```javascript
const VALID_THEMES = ['default', 'cool-neon', 'warm-neon', 'matrix', 'amber-glow'];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=test_admin_can_set_default_theme`
Expected: PASS

- [ ] **Step 6: Run existing theme tests to verify no regression**

Run: `php artisan test --compact --filter=SettingsControllerTest`
Expected: All PASS

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "fix: add 'default' to allowed theme names

The UI offered a 'Default' theme but the backend validation rejected it.
Added 'default' to both the PHP validation rule and the JS VALID_THEMES.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 4: Grouped SettingsNav Sidebar

**Files:**
- Modify: `resources/js/Components/Admin/SettingsNav.vue`
- Test: `tests/JavaScript/Components/SettingsNav.test.js`

- [ ] **Step 1: Write Vitest test for grouped SettingsNav**

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';

// Mock Inertia
vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/settings/integrations' }),
}));

// Mock route helper
globalThis.route = (name) => `/admin/settings/${name.replace('admin.settings.', '')}`;

describe('SettingsNav', () => {
    it('renders grouped section headers', () => {
        const wrapper = mount(SettingsNav, {
            slots: { default: '<div>Content</div>' },
        });

        expect(wrapper.find('[data-testid="settings-nav"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Integrations');
        expect(wrapper.text()).toContain('Features');
        expect(wrapper.text()).toContain('Appearance');
        expect(wrapper.text()).toContain('General');
    });

    it('renders all nav items', () => {
        const wrapper = mount(SettingsNav, {
            slots: { default: '<div>Content</div>' },
        });

        expect(wrapper.text()).toContain('Services');
        expect(wrapper.text()).toContain('Switches');
        expect(wrapper.text()).toContain('Theme');
        expect(wrapper.text()).toContain('Event');
        expect(wrapper.text()).toContain('Portal');
    });

    it('highlights active nav item', () => {
        const wrapper = mount(SettingsNav, {
            slots: { default: '<div>Content</div>' },
        });

        const activeLink = wrapper.find('[data-testid="settings-nav-services"]');
        expect(activeLink.exists()).toBe(true);
        expect(activeLink.classes()).toContain('font-semibold');
    });

    it('renders slot content', () => {
        const wrapper = mount(SettingsNav, {
            slots: { default: '<div data-testid="slot-content">Test</div>' },
        });

        expect(wrapper.find('[data-testid="slot-content"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/JavaScript/Components/SettingsNav.test.js`
Expected: FAIL (no section headers, no "Services" label)

- [ ] **Step 3: Rewrite SettingsNav.vue with grouped sidebar**

Replace `resources/js/Components/Admin/SettingsNav.vue` with:

```vue
<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const currentUrl = computed(() => usePage().url);

const navGroups = [
    {
        label: 'Integrations',
        items: [
            { label: 'Services', href: route('admin.settings.integrations'), testId: 'services' },
            { label: 'Switches', href: route('admin.settings.switches'), testId: 'switches' },
        ],
    },
    {
        label: 'Features',
        items: [
            { label: 'Auto-Allow', href: route('admin.settings.integrations'), testId: 'auto-allow', disabled: true },
            { label: 'IPv6 Detection', href: route('admin.settings.integrations'), testId: 'ipv6', disabled: true },
            { label: 'DNS Warning', href: route('admin.settings.integrations'), testId: 'dns', disabled: true },
        ],
    },
    {
        label: 'Appearance',
        items: [
            { label: 'Theme', href: route('admin.settings.theme'), testId: 'theme' },
        ],
    },
    {
        label: 'General',
        items: [
            { label: 'Event', href: route('admin.settings.event'), testId: 'event' },
            { label: 'Portal', href: route('admin.settings.portal'), testId: 'portal' },
        ],
    },
];

function isActive(href) {
    return currentUrl.value.startsWith(href);
}
</script>

<template>
    <div data-testid="settings-nav" class="flex flex-col lg:flex-row lg:gap-0">
        <nav
            class="shrink-0 border-b border-[var(--color-border)] p-2 lg:w-[200px] lg:border-r-2 lg:border-b-0 lg:py-3"
        >
            <div v-for="group in navGroups" :key="group.label" class="mb-3 last:mb-0">
                <p
                    :data-testid="'settings-nav-group-' + group.label.toLowerCase()"
                    class="px-2 pb-1 text-[8px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
                >
                    {{ group.label }}
                </p>
                <div class="flex flex-wrap gap-0.5 lg:flex-col">
                    <Link
                        v-for="item in group.items"
                        :key="item.testId"
                        :href="item.disabled ? '#' : item.href"
                        :data-testid="'settings-nav-' + item.testId"
                        :class="[
                            isActive(item.href) && !item.disabled
                                ? 'bg-[var(--color-primary)]/10 font-semibold text-[var(--color-primary)]'
                                : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]',
                            item.disabled ? 'pointer-events-none opacity-40' : '',
                        ]"
                        class="rounded-md px-2.5 py-1.5 text-[11px] transition-colors"
                    >
                        {{ item.label }}
                    </Link>
                </div>
            </div>
        </nav>
        <div class="min-w-0 flex-1 p-4 lg:p-6">
            <slot />
        </div>
    </div>
</template>
```

Note: Feature pages (Auto-Allow, IPv6, DNS) are marked `disabled: true` since they currently share the monolithic integrations page. They will be split into separate pages in a future task. For now they're shown in the nav but greyed out.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/JavaScript/Components/SettingsNav.test.js`
Expected: All 4 tests PASS

- [ ] **Step 5: Format + commit**

```bash
npx prettier --write resources/js/Components/Admin/SettingsNav.vue
git add -A && git commit -m "feat: grouped settings sidebar navigation

Restructure SettingsNav from flat list to grouped sections:
INTEGRATIONS (Services, Switches), FEATURES (disabled placeholders),
APPEARANCE (Theme), GENERAL (Event, Portal).

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 5: CapabilityTag Vue Component

**Files:**
- Create: `resources/js/Components/UI/CapabilityTag.vue`
- Test: `tests/JavaScript/Components/CapabilityTag.test.js`

- [ ] **Step 1: Write Vitest test**

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CapabilityTag from '@/Components/UI/CapabilityTag.vue';

describe('CapabilityTag', () => {
    it('renders capability name', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp', active: true },
        });

        expect(wrapper.text()).toBe('dhcp');
    });

    it('renders active state with full color', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp', active: true },
        });

        const tag = wrapper.find('[data-testid="capability-tag"]');
        expect(tag.exists()).toBe(true);
        expect(tag.classes()).not.toContain('opacity-40');
    });

    it('renders inactive state greyed out', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp', active: false },
        });

        const tag = wrapper.find('[data-testid="capability-tag"]');
        expect(tag.classes()).toContain('opacity-40');
    });

    it('defaults to active true', () => {
        const wrapper = mount(CapabilityTag, {
            props: { name: 'dhcp' },
        });

        const tag = wrapper.find('[data-testid="capability-tag"]');
        expect(tag.classes()).not.toContain('opacity-40');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/JavaScript/Components/CapabilityTag.test.js`
Expected: FAIL

- [ ] **Step 3: Create CapabilityTag component**

```vue
<script setup>
defineProps({
    name: { type: String, required: true },
    active: { type: Boolean, default: true },
});
</script>

<template>
    <span
        data-testid="capability-tag"
        :class="[
            active ? '' : 'opacity-40',
            'inline-flex items-center rounded-full border border-[var(--color-primary)]/20 bg-[var(--color-primary)]/10 px-2 py-0.5 text-[10px] font-medium text-[var(--color-primary)]',
        ]"
    >
        {{ name }}
    </span>
</template>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/JavaScript/Components/CapabilityTag.test.js`
Expected: All 4 tests PASS

- [ ] **Step 5: Format + commit**

```bash
npx prettier --write resources/js/Components/UI/CapabilityTag.vue
git add -A && git commit -m "feat: add CapabilityTag component

Pill component showing capability name with active/inactive state.
Inactive tags are greyed out (opacity-40).

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 6: Integration Table Backend + Frontend

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingsController.php`
- Modify: `resources/js/Pages/Admin/Settings/Integrations.vue`
- Modify: `tests/Feature/Admin/SettingsControllerTest.php`

### 6a: Backend — Refactor integrations() method

- [ ] **Step 1: Write failing test for new integrations response shape**

Add to `tests/Feature/Admin/SettingsControllerTest.php`:

```php
public function test_integrations_page_returns_table_data(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opn.local');
    CapabilityAssignment::assign('dhcp', 'opnsense');

    $response = $this->actingAs($admin)->get('/admin/settings/integrations');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/Integrations')
        ->has('services', 5)
        ->where('services.0.id', 'borealis')
        ->has('services.0.capabilities')
    );
}
```

Add `use App\Models\CapabilityAssignment;` to the test file imports.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_integrations_page_returns_table_data`
Expected: FAIL (response shape doesn't match)

- [ ] **Step 3: Refactor SettingsController::integrations()**

Replace the `integrations()` method in `app/Http/Controllers/Admin/SettingsController.php`:

```php
public function integrations(): Response
{
    $services = collect(IntegrationController::INTEGRATION_CAPABILITIES)->map(function (array $capabilities, string $id): array {
        $config = IntegrationConfig::getAll($id);
        $latestTest = ConnectionTestLog::latestFor($id);
        $activeCapabilities = CapabilityAssignment::getForIntegration($id);

        return [
            'id' => $id,
            'name' => self::integrationName($id),
            'enabled' => ! empty($config['endpoint'] ?? $config['enabled'] ?? null),
            'health' => $latestTest?->success,
            'capabilities' => collect($capabilities)->map(fn (string $cap): array => [
                'name' => $cap,
                'active' => $activeCapabilities->contains($cap),
            ])->values()->all(),
        ];
    })->values()->all();

    return Inertia::render('Admin/Settings/Integrations', [
        'services' => $services,
    ]);
}

public static function integrationName(string $id): string
{
    return match ($id) {
        'borealis' => 'Borealis',
        'opnsense' => 'OPNsense',
        'librenms' => 'LibreNMS',
        'ntopng' => 'ntopng',
        'pihole' => 'Pi-hole',
        default => ucfirst($id),
    };
}
```

Add these imports to `SettingsController.php`:

```php
use App\Http\Controllers\Admin\IntegrationController;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
```

Note: `IntegrationController::INTEGRATION_CAPABILITIES` is the constant defined in Task 7. To avoid circular dependency, define this constant directly in `SettingsController` for now, then move it to `IntegrationController` in Task 7. Alternatively, define it in `IntegrationController` first (see Task 7 step ordering).

**Important dependency:** Task 7 creates `IntegrationController` with the constant. To avoid dependency issues, define the constant temporarily in `SettingsController`:

```php
public const INTEGRATION_CAPABILITIES = [
    'borealis' => ['authentication', 'sso', 'user-info'],
    'opnsense' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
    'librenms' => ['ip-to-mac', 'mac-to-port', 'port-bandwidth', 'device-list'],
    'ntopng' => ['user-bandwidth', 'top-talkers', 'aggregate-stats'],
    'pihole' => ['dns-filtering', 'dhcp', 'ip-to-mac'],
];
```

Then reference it as `self::INTEGRATION_CAPABILITIES` instead of `IntegrationController::INTEGRATION_CAPABILITIES`.

- [ ] **Step 4: Update existing integration tests**

The existing `test_admin_can_view_integrations_settings` test asserts a different response shape. Update it:

```php
public function test_admin_can_view_integrations_settings(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->get('/admin/settings/integrations');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/Integrations')
        ->has('services')
    );
}
```

The `test_admin_can_update_integrations_settings` and `test_admin_can_update_existing_integration_setting` tests use the old PUT /admin/settings/integrations endpoint. This endpoint will be removed in Task 7 when individual update endpoints are added. For now, keep the `updateIntegrations` method but mark it as deprecated. The tests remain valid until Task 7.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=SettingsControllerTest`
Expected: All PASS

### 6b: Frontend — Replace Integrations.vue with table

- [ ] **Step 6: Rewrite Integrations.vue**

Replace `resources/js/Pages/Admin/Settings/Integrations.vue` with:

```vue
<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import CapabilityTag from '@/Components/UI/CapabilityTag.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    services: { type: Array, default: () => [] },
});

const columns = [
    { key: 'name', label: 'Service' },
    { key: 'status', label: 'Status' },
    { key: 'health', label: 'Health' },
    { key: 'capabilities', label: 'Capabilities' },
];

function healthDot(health) {
    if (health === null || health === undefined) return 'bg-gray-400';
    return health ? 'bg-green-500' : 'bg-red-500';
}

function healthLabel(health) {
    if (health === null || health === undefined) return 'Unknown';
    return health ? 'Healthy' : 'Unhealthy';
}
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl"
        >
            Integration Services
        </h1>

        <DataTable
            :columns="columns"
            :rows="services"
            :clickable="true"
            :row-href="(row) => route('admin.settings.integrations.show', row.id)"
            data-testid="integrations-table"
        >
            <template #row="{ row }">
                <td class="px-4 py-3 text-sm font-medium text-[var(--color-text)]">
                    {{ row.name }}
                </td>
                <td class="px-4 py-3">
                    <StatusPill
                        :status="row.enabled ? 'success' : 'neutral'"
                        :label="row.enabled ? 'Enabled' : 'Disabled'"
                    />
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span
                            :data-testid="'health-dot-' + row.id"
                            :class="healthDot(row.health)"
                            class="inline-block h-2.5 w-2.5 rounded-full"
                            :title="healthLabel(row.health)"
                        />
                        <span class="text-xs text-[var(--color-text-muted)]">
                            {{ healthLabel(row.health) }}
                        </span>
                    </div>
                </td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        <CapabilityTag
                            v-for="cap in row.capabilities"
                            :key="cap.name"
                            :name="cap.name"
                            :active="cap.active"
                        />
                    </div>
                </td>
            </template>
        </DataTable>
    </SettingsNav>
</template>
```

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
npx prettier --write resources/js/Pages/Admin/Settings/Integrations.vue
git add -A && git commit -m "feat: replace monolithic integrations form with table

Refactor integrations page to show a read-only table with service name,
enabled/disabled status, health dot, and capability tags. Clicking a
row navigates to the per-service config page.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 7: IntegrationController + Config Pages

**Files:**
- Create: `app/Http/Controllers/Admin/IntegrationController.php`
- Create: `resources/js/Pages/Admin/Settings/IntegrationShow.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/IntegrationControllerTest.php`

### 7a: Backend controller + routes

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Admin/IntegrationControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_can_view_integration_show_page(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opn.local');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/opnsense');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/IntegrationShow')
            ->where('service.id', 'opnsense')
            ->has('service.config')
            ->has('service.capabilities')
            ->has('service.logs')
        );
    }

    public function test_show_returns_404_for_invalid_service(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/invalid');

        $response->assertNotFound();
    }

    public function test_can_update_integration_config(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.example.com',
                'key' => 'testkey',
                'secret' => 'testsecret',
            ],
        ]);

        $response->assertRedirect();
        $this->assertEquals('https://opnsense.example.com', IntegrationConfig::getValue('opnsense', 'endpoint'));
    }

    public function test_can_toggle_capability(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
            'active' => true,
        ]);

        $response->assertOk();
        $this->assertTrue(CapabilityAssignment::isActiveProvider('opnsense', 'dhcp'));
    }

    public function test_can_deactivate_capability(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        CapabilityAssignment::assign('dhcp', 'opnsense');

        $response = $this->actingAs($admin)->put('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'opnsense',
            'active' => false,
        ]);

        $response->assertOk();
        $this->assertFalse(CapabilityAssignment::isActiveProvider('opnsense', 'dhcp'));
    }

    public function test_capability_toggle_validates_integration_supports_capability(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/capabilities', [
            'capability' => 'dhcp',
            'integration' => 'librenms',
            'active' => true,
        ]);

        $response->assertUnprocessable();
    }

    public function test_can_get_health_log(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();

        ConnectionTestLog::record('opnsense', true, 'OK');
        ConnectionTestLog::record('opnsense', false, 'Timeout');

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/opnsense/health-log');

        $response->assertOk();
        $response->assertJsonCount(2, 'logs');
    }

    public function test_non_admin_cannot_access_integration(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/settings/integrations/opnsense');

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IntegrationControllerTest`
Expected: FAIL (route doesn't exist)

- [ ] **Step 3: Create IntegrationController**

Run: `php artisan make:controller Admin/IntegrationController --no-interaction`

Replace contents:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\ConnectionTestLog;
use App\Models\IntegrationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class IntegrationController extends Controller
{
    /** @var array<string, list<string>> */
    public const INTEGRATION_CAPABILITIES = [
        'borealis' => ['authentication', 'sso', 'user-info'],
        'opnsense' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
        'librenms' => ['ip-to-mac', 'mac-to-port', 'port-bandwidth', 'device-list'],
        'ntopng' => ['user-bandwidth', 'top-talkers', 'aggregate-stats'],
        'pihole' => ['dns-filtering', 'dhcp', 'ip-to-mac'],
    ];

    /** @var array<string, string> */
    private const INTEGRATION_DESCRIPTIONS = [
        'borealis' => 'SSO authentication provider for user identity and role mapping.',
        'opnsense' => 'Network firewall providing captive portal, rate limiting, and DHCP services.',
        'librenms' => 'Network monitoring for IP/MAC resolution, port mapping, and bandwidth data.',
        'ntopng' => 'Traffic analysis providing per-user bandwidth metrics and top talker data.',
        'pihole' => 'DNS filtering and optional DHCP/IP-to-MAC resolution.',
    ];

    public function show(string $service): Response
    {
        if (! array_key_exists($service, self::INTEGRATION_CAPABILITIES)) {
            throw new NotFoundHttpException("Unknown integration: {$service}");
        }

        $config = IntegrationConfig::getAll($service);
        $capabilities = self::INTEGRATION_CAPABILITIES[$service];
        $activeCapabilities = CapabilityAssignment::getForIntegration($service);
        $logs = ConnectionTestLog::recentFor($service, 20);
        $latestTest = $logs->first();

        return Inertia::render('Admin/Settings/IntegrationShow', [
            'service' => [
                'id' => $service,
                'name' => SettingsController::integrationName($service),
                'description' => self::INTEGRATION_DESCRIPTIONS[$service] ?? '',
                'config' => $config,
                'capabilities' => collect($capabilities)->map(fn (string $cap): array => [
                    'name' => $cap,
                    'active' => $activeCapabilities->contains($cap),
                ])->values()->all(),
                'health' => $latestTest?->success,
                'logs' => $logs->map(fn (ConnectionTestLog $log): array => [
                    'id' => $log->id,
                    'success' => $log->success,
                    'message' => $log->message,
                    'tested_at' => $log->created_at->toIso8601String(),
                ])->values()->all(),
            ],
        ]);
    }

    public function update(Request $request, string $service): RedirectResponse
    {
        if (! array_key_exists($service, self::INTEGRATION_CAPABILITIES)) {
            throw new NotFoundHttpException("Unknown integration: {$service}");
        }

        $validated = $request->validate([
            'config' => 'required|array',
            'config.*' => 'nullable|string|max:500',
        ]);

        foreach ($validated['config'] as $key => $value) {
            $encrypted = in_array($key, IntegrationConfig::ENCRYPTED_KEYS, true);
            IntegrationConfig::setValue($service, $key, $value, $encrypted);
        }

        return back()->with('success', 'Integration settings updated.');
    }

    public function toggleCapability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => 'required|string',
            'integration' => 'required|string',
            'active' => 'required|boolean',
        ]);

        $capabilities = self::INTEGRATION_CAPABILITIES[$validated['integration']] ?? [];
        if (! in_array($validated['capability'], $capabilities, true)) {
            return response()->json([
                'message' => "Integration {$validated['integration']} does not support capability {$validated['capability']}.",
            ], 422);
        }

        if ($validated['active']) {
            CapabilityAssignment::assign($validated['capability'], $validated['integration']);
        } else {
            CapabilityAssignment::unassign($validated['capability']);
        }

        return response()->json(['success' => true]);
    }

    public function healthLog(string $service): JsonResponse
    {
        if (! array_key_exists($service, self::INTEGRATION_CAPABILITIES)) {
            throw new NotFoundHttpException("Unknown integration: {$service}");
        }

        $logs = ConnectionTestLog::recentFor($service, 20);

        return response()->json([
            'logs' => $logs->map(fn (ConnectionTestLog $log): array => [
                'id' => $log->id,
                'success' => $log->success,
                'message' => $log->message,
                'tested_at' => $log->created_at->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
```

- [ ] **Step 4: Add routes to web.php**

Add after the existing settings routes block in `routes/web.php`:

```php
use App\Http\Controllers\Admin\IntegrationController;
```

And add these routes inside the admin middleware group:

```php
        Route::get('/settings/integrations/{service}', [IntegrationController::class, 'show'])->name('settings.integrations.show');
        Route::put('/settings/integrations/{service}', [IntegrationController::class, 'update'])->name('settings.integrations.update');
        Route::put('/settings/capabilities', [IntegrationController::class, 'toggleCapability'])->name('settings.capabilities.update');
        Route::get('/settings/integrations/{service}/health-log', [IntegrationController::class, 'healthLog'])->name('settings.integrations.health-log');
```

**Important:** These must be placed AFTER the existing `Route::get('/settings/integrations', ...)` and `Route::put('/settings/integrations', ...)` routes to avoid route conflicts (the `{service}` parameter would catch "integrations" otherwise).

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=IntegrationControllerTest`
Expected: All 8 tests PASS

### 7b: Frontend — IntegrationShow.vue

- [ ] **Step 6: Create IntegrationShow.vue**

Create `resources/js/Pages/Admin/Settings/IntegrationShow.vue`:

```vue
<script setup>
import { useForm, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import CapabilityTag from '@/Components/UI/CapabilityTag.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import FormField from '@/Components/UI/FormField.vue';
import { ref } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    service: { type: Object, required: true },
});

const form = useForm({
    config: { ...props.service.config },
});

const testingConnection = ref(false);
const testResult = ref(null);

function submit() {
    form.put(route('admin.settings.integrations.update', props.service.id));
}

async function testConnection() {
    testingConnection.value = true;
    testResult.value = null;

    try {
        const routeName = `admin.settings.test.${props.service.id}`;
        const response = await fetch(route(routeName), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
        });
        testResult.value = await response.json();
    } catch {
        testResult.value = { success: false, message: 'Request failed' };
    } finally {
        testingConnection.value = false;
    }
}

async function toggleCapability(capability, currentActive) {
    try {
        await fetch(route('admin.settings.capabilities.update'), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify({
                capability: capability,
                integration: props.service.id,
                active: !currentActive,
            }),
        });
        router.reload({ only: ['service'] });
    } catch {
        // Silently fail — reload will show current state
    }
}

function healthDot(health) {
    if (health === null || health === undefined) return 'bg-gray-400';
    return health ? 'bg-green-500' : 'bg-red-500';
}
</script>

<template>
    <SettingsNav>
        <!-- Back link -->
        <Link
            :href="route('admin.settings.integrations')"
            data-testid="back-link"
            class="mb-4 inline-flex items-center gap-1 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
        >
            ← Back to Services
        </Link>

        <!-- Header -->
        <div class="mb-6 flex items-start justify-between">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                >
                    {{ service.name }}
                </h1>
                <p class="mt-1 text-sm text-[var(--color-text-muted)]">{{ service.description }}</p>
            </div>
            <div class="flex items-center gap-2">
                <span
                    data-testid="health-indicator"
                    :class="healthDot(service.health)"
                    class="inline-block h-3 w-3 rounded-full"
                />
            </div>
        </div>

        <!-- Capabilities Section -->
        <div
            data-testid="capabilities-section"
            class="mb-6 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
        >
            <h2 class="mb-3 text-sm font-semibold text-[var(--color-text)]">Capabilities</h2>
            <div class="space-y-3">
                <div
                    v-for="cap in service.capabilities"
                    :key="cap.name"
                    class="flex items-center justify-between"
                >
                    <div class="flex items-center gap-2">
                        <CapabilityTag :name="cap.name" :active="cap.active" />
                    </div>
                    <button
                        type="button"
                        :data-testid="'capability-toggle-' + cap.name"
                        :class="[
                            cap.active
                                ? 'bg-[var(--color-primary)]'
                                : 'bg-[var(--color-text-muted)]/30',
                            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors',
                        ]"
                        @click="toggleCapability(cap.name, cap.active)"
                    >
                        <span
                            :class="[
                                cap.active ? 'translate-x-5' : 'translate-x-0.5',
                                'pointer-events-none mt-0.5 inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
                            ]"
                        />
                    </button>
                </div>
            </div>
        </div>

        <!-- Config Form -->
        <form
            data-testid="config-form"
            class="mb-6 space-y-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
            @submit.prevent="submit"
        >
            <h2 class="mb-3 text-sm font-semibold text-[var(--color-text)]">Configuration</h2>

            <FormField
                v-for="(value, key) in form.config"
                :key="key"
                :label="key"
                :name="'config.' + key"
            >
                <input
                    v-model="form.config[key]"
                    :data-testid="'config-field-' + key"
                    :type="['password', 'secret', 'api_key', 'key'].some((k) => key.includes(k)) ? 'password' : 'text'"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] placeholder-[var(--color-text-muted)] focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)] focus:outline-none"
                />
            </FormField>

            <div class="flex items-center gap-3 pt-2">
                <button
                    type="submit"
                    data-testid="action-save"
                    :disabled="form.processing"
                    class="rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-hover)]"
                >
                    Save Settings
                </button>
                <button
                    type="button"
                    data-testid="action-test"
                    :disabled="testingConnection"
                    class="rounded-lg border border-[var(--color-border)] px-3.5 py-1.5 text-sm font-medium text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                    @click="testConnection"
                >
                    {{ testingConnection ? 'Testing...' : 'Test Connection' }}
                </button>
            </div>

            <!-- Test result -->
            <div
                v-if="testResult"
                :data-testid="'test-result-' + (testResult.success ? 'success' : 'failure')"
                :class="[
                    testResult.success
                        ? 'border-[var(--color-success)]/20 bg-[var(--color-success)]/10 text-[var(--color-success)]'
                        : 'border-[var(--color-danger)]/20 bg-[var(--color-danger)]/10 text-[var(--color-danger)]',
                    'rounded-lg border p-3 text-sm',
                ]"
            >
                {{ testResult.message }}
            </div>
        </form>

        <!-- Connection History -->
        <div
            v-if="service.logs.length"
            data-testid="connection-history"
            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
        >
            <h2 class="mb-3 text-sm font-semibold text-[var(--color-text)]">Connection History</h2>
            <div class="space-y-2">
                <div
                    v-for="log in service.logs"
                    :key="log.id"
                    data-testid="history-entry"
                    class="flex items-center gap-3 text-sm"
                >
                    <span
                        :class="log.success ? 'bg-green-500' : 'bg-red-500'"
                        class="inline-block h-2 w-2 shrink-0 rounded-full"
                    />
                    <span class="text-[var(--color-text-muted)]">
                        {{ new Date(log.tested_at).toLocaleString() }}
                    </span>
                    <span class="text-[var(--color-text)]">{{ log.message }}</span>
                </div>
            </div>
        </div>
    </SettingsNav>
</template>
```

- [ ] **Step 7: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
npx prettier --write resources/js/Pages/Admin/Settings/IntegrationShow.vue
git add -A && git commit -m "feat: add per-service integration config pages

New IntegrationController with show/update/capability-toggle/health-log
endpoints. IntegrationShow.vue shows capability toggles, config form,
test connection button, and connection history.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 8: Connection Test Logging

**Files:**
- Modify: `app/Http/Controllers/Admin/TestConnectionController.php`
- Modify: `tests/Feature/Admin/TestConnectionControllerTest.php`

- [ ] **Step 1: Write failing test — test connection records a log entry**

Add to `tests/Feature/Admin/TestConnectionControllerTest.php`:

```php
public function test_opnsense_test_records_connection_log(): void
{
    Queue::fake();
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
    $admin = $this->createAdminUser();

    IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');
    IntegrationConfig::setValue('opnsense', 'key', 'test-key');
    IntegrationConfig::setValue('opnsense', 'secret', 'test-secret', true);

    $this->actingAs($admin)->post('/admin/settings/test/opnsense');

    $this->assertDatabaseHas('connection_test_logs', [
        'integration' => 'opnsense',
        'success' => true,
    ]);
}

public function test_failed_connection_records_failure_log(): void
{
    Queue::fake();
    Http::fake(['*' => Http::response('Server Error', 500)]);
    $admin = $this->createAdminUser();

    IntegrationConfig::setValue('opnsense', 'endpoint', 'https://opnsense.example.com');
    IntegrationConfig::setValue('opnsense', 'key', 'test-key');
    IntegrationConfig::setValue('opnsense', 'secret', 'test-secret', true);

    $this->actingAs($admin)->post('/admin/settings/test/opnsense');

    $this->assertDatabaseHas('connection_test_logs', [
        'integration' => 'opnsense',
        'success' => false,
    ]);
}
```

Add `use App\Models\ConnectionTestLog;` to test imports if not present.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_opnsense_test_records_connection_log`
Expected: FAIL

- [ ] **Step 3: Add logging to TestConnectionController methods**

Add `use App\Models\ConnectionTestLog;` import.

Update each test method to record the result. Example for `testOpnsense()`:

```php
public function testOpnsense(): JsonResponse
{
    try {
        $config = IntegrationConfig::getAll('opnsense');
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $response = Http::withOptions([
            'verify' => (bool) ($config['verify_ssl'] ?? true),
        ])
            ->withBasicAuth($config['key'] ?? '', $config['secret'] ?? '')
            ->timeout(10)
            ->get($endpoint.'/api/captiveportal/service/reconfigure');

        $response->throw();

        ConnectionTestLog::record('opnsense', true, 'Connected successfully');

        return response()->json(['success' => true, 'message' => 'Connected successfully']);
    } catch (Throwable $throwable) {
        $message = 'Connection failed: '.$throwable->getMessage();
        ConnectionTestLog::record('opnsense', false, $message);

        return response()->json(['success' => false, 'message' => $message]);
    }
}
```

Apply the same pattern to `testLibrenms()`, `testNtopng()`, `testPihole()`, and `testSwitch()` — adding `ConnectionTestLog::record(...)` calls in both the success and catch blocks. For `testSwitch()`, use `'switch-' . $switchConfig->hostname` as the integration identifier.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=TestConnectionControllerTest`
Expected: All tests PASS (including new log assertions)

- [ ] **Step 5: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: record connection test results to log table

Each test connection now records success/failure to connection_test_logs
for display in the integration config page's connection history section.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 9: Theme Live Preview

**Files:**
- Modify: `resources/js/composables/useTheme.js`
- Modify: `resources/js/Pages/Admin/Settings/Theme.vue`
- Test: `tests/JavaScript/composables/useTheme.test.js`

- [ ] **Step 1: Write Vitest test for preview/cancel functionality**

Create `tests/JavaScript/composables/useTheme.test.js`:

```javascript
import { describe, it, expect, beforeEach, vi } from 'vitest';

// Mock Inertia usePage
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { theme: { name: 'cool-neon', mode: 'dark' } } }),
}));

// Mock localStorage
const localStorageMock = { getItem: vi.fn(), setItem: vi.fn() };
vi.stubGlobal('localStorage', localStorageMock);

import { useTheme } from '@/composables/useTheme';

describe('useTheme', () => {
    beforeEach(() => {
        document.documentElement.removeAttribute('data-theme');
        document.documentElement.removeAttribute('data-mode');
        localStorageMock.getItem.mockReturnValue(null);
    });

    it('returns valid themes list including default', () => {
        const { themes } = useTheme();
        expect(themes).toContain('default');
        expect(themes).toContain('cool-neon');
    });

    it('previewTheme applies theme without saving to localStorage', () => {
        const { previewTheme } = useTheme();
        previewTheme('matrix');

        expect(document.documentElement.getAttribute('data-theme')).toBe('matrix');
        expect(localStorageMock.setItem).not.toHaveBeenCalledWith('theme', 'matrix');
    });

    it('cancelPreview restores original theme', () => {
        const { previewTheme, cancelPreview, theme } = useTheme();
        const original = theme.value;

        previewTheme('matrix');
        cancelPreview();

        expect(document.documentElement.getAttribute('data-theme')).toBe(original);
    });

    it('setTheme saves to localStorage', () => {
        const { setTheme } = useTheme();
        setTheme('warm-neon');

        expect(localStorageMock.setItem).toHaveBeenCalledWith('theme', 'warm-neon');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/JavaScript/composables/useTheme.test.js`
Expected: FAIL (`previewTheme` not defined)

- [ ] **Step 3: Add previewTheme/cancelPreview to useTheme.js**

Replace `resources/js/composables/useTheme.js`:

```javascript
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const VALID_THEMES = ['default', 'cool-neon', 'warm-neon', 'matrix', 'amber-glow'];
const VALID_MODES = ['light', 'dark'];

export function useTheme() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};

    const theme = ref(sharedTheme.name || localStorage.getItem('theme') || 'cool-neon');
    const mode = ref(sharedTheme.mode || localStorage.getItem('themeMode') || 'dark');
    const savedTheme = ref(theme.value);
    const savedMode = ref(mode.value);

    function applyTheme() {
        const el = document.documentElement;
        el.setAttribute('data-theme', theme.value);
        el.setAttribute('data-mode', mode.value);
    }

    function setTheme(name) {
        if (VALID_THEMES.includes(name)) {
            theme.value = name;
            savedTheme.value = name;
            localStorage.setItem('theme', name);
            applyTheme();
        }
    }

    function toggleMode() {
        mode.value = mode.value === 'dark' ? 'light' : 'dark';
        savedMode.value = mode.value;
        localStorage.setItem('themeMode', mode.value);
        applyTheme();
    }

    function setMode(newMode) {
        if (VALID_MODES.includes(newMode)) {
            mode.value = newMode;
            savedMode.value = newMode;
            localStorage.setItem('themeMode', newMode);
            applyTheme();
        }
    }

    function previewTheme(name) {
        if (VALID_THEMES.includes(name)) {
            theme.value = name;
            applyTheme();
        }
    }

    function previewMode(newMode) {
        if (VALID_MODES.includes(newMode)) {
            mode.value = newMode;
            applyTheme();
        }
    }

    function cancelPreview() {
        theme.value = savedTheme.value;
        mode.value = savedMode.value;
        applyTheme();
    }

    watch([theme, mode], applyTheme, { immediate: true });

    return {
        theme,
        mode,
        setTheme,
        toggleMode,
        setMode,
        previewTheme,
        previewMode,
        cancelPreview,
        themes: VALID_THEMES,
    };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/JavaScript/composables/useTheme.test.js`
Expected: All 4 tests PASS

- [ ] **Step 5: Update Theme.vue for live preview**

In `resources/js/Pages/Admin/Settings/Theme.vue`, add the import and use preview:

Replace the `<script setup>` block:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';
import { useTheme } from '@/composables/useTheme.js';
import { onBeforeUnmount } from 'vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const { previewTheme, previewMode, cancelPreview } = useTheme();

const form = useForm({
    theme_name: props.settings?.theme_name ?? 'cool-neon',
    theme_mode: props.settings?.theme_mode ?? 'dark',
});

const themes = [
    { name: 'default', label: 'Default', colors: ['#6366f1', '#e11d48', '#059669'] },
    { name: 'cool-neon', label: 'Cool Neon', colors: ['#06b6d4', '#8b5cf6', '#22d3ee'] },
    { name: 'warm-neon', label: 'Warm Neon', colors: ['#ec4899', '#a855f7', '#f43f5e'] },
    { name: 'matrix', label: 'Matrix', colors: ['#22c55e', '#84cc16', '#14b8a6'] },
    { name: 'amber-glow', label: 'Amber Glow', colors: ['#f59e0b', '#ef4444', '#d97706'] },
];

const modes = ['light', 'dark'];

function selectTheme(name) {
    form.theme_name = name;
    previewTheme(name);
}

function selectMode(mode) {
    form.theme_mode = mode;
    previewMode(mode);
}

function submit() {
    form.put(route('admin.settings.theme.update'));
}

onBeforeUnmount(() => {
    cancelPreview();
});
</script>
```

Update the template theme button `@click` from `form.theme_name = theme.name` to `selectTheme(theme.name)`, and mode button `@click` from `form.theme_mode = mode` to `selectMode(mode)`.

- [ ] **Step 6: Format + commit**

```bash
npx prettier --write resources/js/composables/useTheme.js resources/js/Pages/Admin/Settings/Theme.vue
git add -A && git commit -m "feat: add live preview to theme settings page

Clicking a theme swatch now immediately applies it as a preview.
Navigating away cancels the preview. Only persisted on Save.
Added previewTheme/previewMode/cancelPreview to useTheme composable.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 10: Remove Old Monolithic Integration Update + Clean Up Routes

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingsController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Admin/SettingsControllerTest.php`
- Modify: `tests/Feature/Admin/SettingsControllerIntegrationExpansionTest.php`

- [ ] **Step 1: Remove old updateIntegrations method from SettingsController**

Delete the `updateIntegrations` method and the `INTEGRATION_CAPABILITIES` constant from `SettingsController.php` (it now lives in `IntegrationController`). Also update the `integrations()` method to reference `IntegrationController::INTEGRATION_CAPABILITIES`.

- [ ] **Step 2: Remove old PUT route**

In `routes/web.php`, remove:
```php
Route::put('/settings/integrations', [SettingsController::class, 'updateIntegrations'])->name('settings.integrations.update');
```

- [ ] **Step 3: Update tests**

Remove `test_admin_can_update_integrations_settings` and `test_admin_can_update_existing_integration_setting` from `SettingsControllerTest.php` — these tested the old monolithic update endpoint which is replaced by `IntegrationController@update`.

Update the `SettingsControllerIntegrationExpansionTest.php` to use the new per-service endpoint instead. Each test that POSTs to `/admin/settings/integrations` should be updated to PUT to `/admin/settings/integrations/{service}` with the new request format.

- [ ] **Step 4: Run all settings tests**

Run: `php artisan test --compact --filter=Settings`
Expected: All PASS

- [ ] **Step 5: Run full test suite**

Run: `php artisan test --compact`
Expected: All PASS

- [ ] **Step 6: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "refactor: remove monolithic integration update endpoint

Replace single PUT /settings/integrations with per-service endpoints.
Update tests to use IntegrationController@update.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 11: Quality Pass

- [ ] **Step 1: Run full PHP quality checks**

```bash
vendor/bin/pint --format agent
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
```

Fix any issues.

- [ ] **Step 2: Run full JS quality checks**

```bash
npm run lint
npm run format:check
```

Fix any issues.

- [ ] **Step 3: Run full test suites**

```bash
php artisan test --compact
npx vitest run
```

All must pass.

- [ ] **Step 4: Final commit if any fixes**

```bash
git add -A && git commit -m "chore: quality pass — lint, format, static analysis

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Self-Review Checklist

### Spec Coverage
- [x] Section 1.1: Integration Table Page → Task 6 (backend + frontend)
- [x] Section 1.2: Integration Config Page → Task 7 (IntegrationController + IntegrationShow.vue)
- [x] Section 1.3: Integration Definitions → constant in IntegrationController
- [x] Section 1.4: Capability Assignment Model → Task 1 + Task 7 (toggleCapability)
- [x] Section 1.5: Switches Page → unchanged, new nav via Task 4
- [x] Section 1.6: Feature Settings Pages → disabled nav items in Task 4 (future scope)
- [x] Section 1.7: Theme/Event/Portal → Theme fixed in Tasks 3 + 9, Event/Portal unchanged
- [x] Section 6.1: Validation fix → Task 3
- [x] Section 6.2: Amber Glow CSS → exists, no work needed
- [x] Section 6.3: Live Preview → Task 9

### Placeholder Scan
- No TBD/TODO found
- All code blocks contain actual implementation code
- All test assertions are specific

### Type Consistency
- `CapabilityAssignment::assign()`, `unassign()`, `isActiveProvider()`, `getForIntegration()` — consistent across Tasks 1, 6, 7
- `ConnectionTestLog::record()`, `recentFor()`, `latestFor()` — consistent across Tasks 2, 7, 8
- `IntegrationController::INTEGRATION_CAPABILITIES` — defined in Task 7, referenced in Tasks 6 and 10
- `SettingsController::integrationName()` — defined in Task 6, referenced in Task 7
- `previewTheme`/`previewMode`/`cancelPreview` — defined in Task 9, used in Theme.vue

### Notes
- Feature settings pages (Auto-Allow, IPv6, DNS) are shown as disabled in the nav. They currently share the monolithic integrations endpoint and will be split into separate routes/pages in a follow-up plan.
- Borealis config is included in the capability map but its config fields differ from the other integrations (it uses env-based OAuth config). The IntegrationShow.vue renders whatever keys exist in `IntegrationConfig::getAll()`, so it will work but may need a custom form layout in a future iteration.
