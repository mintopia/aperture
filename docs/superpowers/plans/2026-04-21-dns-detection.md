# DNS Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins configure an optional DNS detection check URL so the Inertia dashboard warns users not on event DNS servers.

**Architecture:** Admin stores `dns.check_url` and `dns.warning_message` in the `settings` table via a new settings controller. The Inertia dashboard reads these settings and the Vue component performs a client-side fetch, replacing `{uuid}` with a random UUID. The response `{"server":"event"}` means pass; `{"server":"online"}` or any error means treat as pass (no warning). On failure, retries every 60s with a manual refresh option.

**Tech Stack:** Laravel 12 (PHP 8.5), Vue 3 + Inertia, Vitest, PHPUnit, Playwright

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Http/Controllers/Admin/DnsDetectionSettingsController.php` | Admin CRUD for dns.check_url and dns.warning_message settings |
| Create | `resources/js/Pages/Admin/Settings/DnsDetection.vue` | Admin settings form page |
| Create | `tests/Feature/Admin/DnsDetectionSettingsControllerTest.php` | PHP feature tests for settings controller |
| Create | `tests/js/Pages/Admin/Settings/DnsDetection.spec.js` | JS tests for admin settings page |
| Modify | `routes/web.php:135-136` | Add GET/PUT routes for dns-detection settings |
| Modify | `resources/js/Components/Admin/SettingsNav.vue:13` | Enable DNS Detection nav link |
| Modify | `app/Http/Controllers/Portal/DashboardController.php:14-31` | Pass dnsDetection prop |
| Modify | `resources/js/Pages/Portal/Dashboard.vue:1-60` | Use dnsDetection prop instead of content block |
| Modify | `resources/js/Components/Blocks/DnsWarningBlock.vue` | Rewrite: client-side fetch with retry and refresh |
| Modify | `tests/Feature/Portal/DashboardControllerTest.php:114-136` | Replace old DNS test with new dnsDetection prop tests |
| Rewrite | `tests/js/Components/Blocks/DnsWarningBlock.spec.js` | Rewrite for new component interface |
| Modify | `tests/js/Components/Admin/SettingsNav.spec.js:85` | DNS Detection is now a link |
| Remove test | `tests/Unit/Config/ApertureConfigTest.php:43-46` | Remove test_dns_config_removed (no longer relevant) |

---

### Task 1: Admin Settings Controller — Tests

**Files:**
- Create: `tests/Feature/Admin/DnsDetectionSettingsControllerTest.php`

- [ ] **Step 1: Create the test file**

```bash
php artisan make:test Admin/DnsDetectionSettingsControllerTest --phpunit --no-interaction
```

- [ ] **Step 2: Write failing tests**

Replace the generated file contents with:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DnsDetectionSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = $this->createAdminUser();
    }

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

    public function test_show_returns_settings_page_with_defaults(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/dns-detection');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Settings/DnsDetection')
            ->has('settings')
            ->where('settings.dns_check_url', '')
            ->where('settings.dns_warning_message', '')
        );
    }

    public function test_show_returns_existing_settings(): void
    {
        Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.lancache.test.entropylan.party']);
        Setting::create(['code' => 'dns.warning_message', 'name' => 'DNS Warning Message', 'value' => 'Fix your DNS!']);

        $response = $this->actingAs($this->admin)->get('/admin/settings/dns-detection');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('settings.dns_check_url', 'https://{uuid}.lancache.test.entropylan.party')
            ->where('settings.dns_warning_message', 'Fix your DNS!')
        );
    }

    public function test_update_saves_settings(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://{uuid}.lancache.test.entropylan.party',
            'dns_warning_message' => 'Please use event DNS.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('https://{uuid}.lancache.test.entropylan.party', Setting::get('dns.check_url'));
        $this->assertSame('Please use event DNS.', Setting::get('dns.warning_message'));
    }

    public function test_update_clears_settings_when_empty(): void
    {
        Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.example.com']);
        Setting::create(['code' => 'dns.warning_message', 'name' => 'DNS Warning Message', 'value' => 'Old message']);

        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => '',
            'dns_warning_message' => '',
        ]);

        $response->assertRedirect();
        $this->assertNull(Setting::get('dns.check_url'));
        $this->assertNull(Setting::get('dns.warning_message'));
    }

    public function test_update_rejects_url_without_uuid_placeholder(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://lancache.test.entropylan.party',
            'dns_warning_message' => 'Fix your DNS.',
        ]);

        $response->assertSessionHasErrors('dns_check_url');
    }

    public function test_update_rejects_warning_message_over_500_chars(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://{uuid}.lancache.test.entropylan.party',
            'dns_warning_message' => str_repeat('a', 501),
        ]);

        $response->assertSessionHasErrors('dns_warning_message');
    }

    public function test_update_accepts_url_with_uuid_placeholder(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/dns-detection', [
            'dns_check_url' => 'https://{uuid}.lancache.test.entropylan.party',
            'dns_warning_message' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_requires_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/dns-detection')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/dns-detection', [])->assertForbidden();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=DnsDetectionSettingsControllerTest`
Expected: All tests FAIL (route not found / controller not found)

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Admin/DnsDetectionSettingsControllerTest.php
git commit -m "test: add failing tests for DNS detection settings controller"
```

---

### Task 2: Admin Settings Controller — Implementation

**Files:**
- Create: `app/Http/Controllers/Admin/DnsDetectionSettingsController.php`
- Modify: `routes/web.php:136` (add routes after portal settings)

- [ ] **Step 1: Create the controller**

```bash
php artisan make:class App/Http/Controllers/Admin/DnsDetectionSettingsController --no-interaction
```

Replace the generated file contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsDetectionSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/DnsDetection', [
            'settings' => [
                'dns_check_url' => Setting::get('dns.check_url', ''),
                'dns_warning_message' => Setting::get('dns.warning_message', ''),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Settings'],
                ['label' => 'DNS Detection'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dns_check_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! str_contains($value, '{uuid}')) {
                        $fail('The URL must contain the {uuid} placeholder.');
                    }
                },
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! filter_var(str_replace('{uuid}', 'test', $value), FILTER_VALIDATE_URL)) {
                        $fail('The URL must be a valid URL.');
                    }
                },
            ],
            'dns_warning_message' => 'nullable|string|max:500',
        ]);

        $this->saveSetting('dns.check_url', 'DNS Check URL', $validated['dns_check_url']);
        $this->saveSetting('dns.warning_message', 'DNS Warning Message', $validated['dns_warning_message']);

        return back()->with('success', 'DNS detection settings updated.');
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();
        if (! $setting) {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
        }

        $setting->value = $value;
        $setting->save();
    }
}
```

- [ ] **Step 2: Add routes to `routes/web.php`**

After the existing portal settings routes (around line 134), add:

```php
Route::get('/settings/dns-detection', [DnsDetectionSettingsController::class, 'show'])->name('settings.dns-detection');
Route::put('/settings/dns-detection', [DnsDetectionSettingsController::class, 'update'])->name('settings.dns-detection.update');
```

Add the import at the top of `routes/web.php`:

```php
use App\Http\Controllers\Admin\DnsDetectionSettingsController;
```

- [ ] **Step 3: Create stub Vue page** (minimal to pass Inertia render)

Create `resources/js/Pages/Admin/Settings/DnsDetection.vue`:

```vue
<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    settings: { type: Object, default: () => ({}) },
});
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] font-bold tracking-[-0.03em] leading-[1.1] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            DNS Detection
        </h1>
        <p class="text-sm text-[var(--color-text-muted)]">Settings placeholder</p>
    </SettingsNav>
</template>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=DnsDetectionSettingsControllerTest`
Expected: All 8 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/DnsDetectionSettingsController.php routes/web.php resources/js/Pages/Admin/Settings/DnsDetection.vue
git commit -m "feat: add DNS detection settings controller, routes, and stub page"
```

---

### Task 3: Admin Settings Vue Page

**Files:**
- Create: `tests/js/Pages/Admin/Settings/DnsDetection.spec.js`
- Modify: `resources/js/Pages/Admin/Settings/DnsDetection.vue`

- [ ] **Step 1: Write failing JS tests**

Create `tests/js/Pages/Admin/Settings/DnsDetection.spec.js`:

```js
import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import DnsDetection from '@/Pages/Admin/Settings/DnsDetection.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: vi.fn(),
        processing: false,
        errors: {},
    })),
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ url: '/admin/settings/dns-detection' }),
}));

window.route = vi.fn((name) => `/mocked/${name}`);

const SettingsNavStub = { template: '<div><slot /></div>' };
const FormFieldStub = {
    template: '<div><slot /></div>',
    props: ['label', 'name', 'error', 'required'],
};

function mountPage(settings = {}) {
    return mount(DnsDetection, {
        props: {
            settings: {
                dns_check_url: '',
                dns_warning_message: '',
                ...settings,
            },
        },
        global: {
            stubs: {
                SettingsNav: SettingsNavStub,
                FormField: FormFieldStub,
                AdminLayout: { template: '<div><slot /></div>' },
            },
        },
    });
}

describe('DnsDetection settings page', () => {
    it('renders page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('DNS Detection');
    });

    it('renders check URL input with existing value', () => {
        const wrapper = mountPage({ dns_check_url: 'https://{uuid}.example.com' });
        const input = wrapper.find('[data-testid="input-dns-check-url"]');
        expect(input.exists()).toBe(true);
        expect(input.element.value).toBe('https://{uuid}.example.com');
    });

    it('renders warning message textarea with existing value', () => {
        const wrapper = mountPage({ dns_warning_message: 'Fix your DNS!' });
        const textarea = wrapper.find('[data-testid="input-dns-warning-message"]');
        expect(textarea.exists()).toBe(true);
        expect(textarea.element.value).toBe('Fix your DNS!');
    });

    it('renders save button', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="action-save"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Pages/Admin/Settings/DnsDetection.spec.js`
Expected: FAIL (inputs don't exist yet)

- [ ] **Step 3: Implement the full Vue page**

Replace `resources/js/Pages/Admin/Settings/DnsDetection.vue`:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    dns_check_url: props.settings?.dns_check_url ?? '',
    dns_warning_message: props.settings?.dns_warning_message ?? '',
});

function submit() {
    form.put(route('admin.settings.dns-detection.update'));
}
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] font-bold tracking-[-0.03em] leading-[1.1] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            DNS Detection
        </h1>

        <p class="mb-6 text-[13px] text-[var(--color-text-muted)]">
            Configure a URL to check whether users are using the event DNS servers. The URL must contain
            <code class="rounded bg-[var(--color-surface-hover)] px-1 py-0.5 text-[12px]">{uuid}</code> which is
            replaced with a random value to prevent caching. Leave empty to disable.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Check URL" name="dns_check_url" :error="form.errors.dns_check_url">
                <input
                    id="dns_check_url"
                    v-model="form.dns_check_url"
                    type="text"
                    data-testid="input-dns-check-url"
                    placeholder="https://{uuid}.lancache.test.entropylan.party"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="Warning Message" name="dns_warning_message" :error="form.errors.dns_warning_message">
                <textarea
                    id="dns_warning_message"
                    v-model="form.dns_warning_message"
                    rows="3"
                    data-testid="input-dns-warning-message"
                    placeholder="Your device is not using the event DNS servers. Please update your DNS settings."
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <button
                type="submit"
                data-testid="action-save"
                :disabled="form.processing"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
            >
                Save Settings
            </button>
        </form>
    </SettingsNav>
</template>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Pages/Admin/Settings/DnsDetection.spec.js`
Expected: All 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Settings/DnsDetection.vue tests/js/Pages/Admin/Settings/DnsDetection.spec.js
git commit -m "feat: implement DNS detection admin settings page with tests"
```

---

### Task 4: Enable DNS Detection in Settings Nav

**Files:**
- Modify: `resources/js/Components/Admin/SettingsNav.vue:13`
- Modify: `tests/js/Components/Admin/SettingsNav.spec.js`

- [ ] **Step 1: Update the SettingsNav spec**

In `tests/js/Components/Admin/SettingsNav.spec.js`, find the test that checks DNS Detection is disabled and change it to verify it's now an active link. The test should check that:
- The element with `data-testid="settings-nav-dns-detection"` exists
- It does NOT have the "Soon" badge text
- It is a link (not a span)

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/Components/Admin/SettingsNav.spec.js`
Expected: FAIL (still disabled)

- [ ] **Step 3: Update SettingsNav.vue**

In `resources/js/Components/Admin/SettingsNav.vue`, change the DNS Detection nav item from disabled to an active link:

Replace:
```js
{ label: 'DNS Detection', disabled: true },
```

With:
```js
{ label: 'DNS Detection', href: route('admin.settings.dns-detection') },
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run tests/js/Components/Admin/SettingsNav.spec.js`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/SettingsNav.vue tests/js/Components/Admin/SettingsNav.spec.js
git commit -m "feat: enable DNS Detection link in settings navigation"
```

---

### Task 5: Dashboard Controller — Pass DNS Detection Settings

**Files:**
- Modify: `tests/Feature/Portal/DashboardControllerTest.php:114-136`
- Modify: `app/Http/Controllers/Portal/DashboardController.php`

- [ ] **Step 1: Replace the old DNS test and add new tests**

In `tests/Feature/Portal/DashboardControllerTest.php`, replace the `test_dns_warning_block_passes_settings_with_expected_dns` method with these tests:

```php
public function test_dashboard_passes_dns_detection_when_configured(): void
{
    Queue::fake();
    $user = User::factory()->create();

    Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.lancache.test.entropylan.party']);
    Setting::create(['code' => 'dns.warning_message', 'name' => 'DNS Warning Message', 'value' => 'Fix your DNS!']);

    $response = $this->actingAs($user)->get('/portal');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/Dashboard')
        ->has('dnsDetection')
        ->where('dnsDetection.checkUrl', 'https://{uuid}.lancache.test.entropylan.party')
        ->where('dnsDetection.warningMessage', 'Fix your DNS!')
    );
}

public function test_dashboard_passes_null_dns_detection_when_not_configured(): void
{
    Queue::fake();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/portal');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/Dashboard')
        ->where('dnsDetection', null)
    );
}

public function test_dashboard_uses_default_warning_message_when_not_set(): void
{
    Queue::fake();
    $user = User::factory()->create();

    Setting::create(['code' => 'dns.check_url', 'name' => 'DNS Check URL', 'value' => 'https://{uuid}.lancache.test.entropylan.party']);

    $response = $this->actingAs($user)->get('/portal');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('dnsDetection.checkUrl', 'https://{uuid}.lancache.test.entropylan.party')
        ->where('dnsDetection.warningMessage', 'Your device is not using the event DNS servers. Please update your DNS settings.')
    );
}
```

Add `use App\Models\Setting;` to the imports at the top.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=DashboardControllerTest --test-directory=tests/Feature/Portal`
Expected: New tests FAIL (dnsDetection prop not passed yet)

- [ ] **Step 3: Update DashboardController**

In `app/Http/Controllers/Portal/DashboardController.php`, add the Setting import and pass dnsDetection:

Add import:
```php
use App\Models\Setting;
```

In the `index` method, before the `return Inertia::render(...)`, add:

```php
$checkUrl = Setting::get('dns.check_url');
$warningMessage = Setting::get('dns.warning_message');
```

Add to the Inertia::render array:

```php
'dnsDetection' => $checkUrl ? [
    'checkUrl' => $checkUrl,
    'warningMessage' => $warningMessage ?? 'Your device is not using the event DNS servers. Please update your DNS settings.',
] : null,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=DashboardControllerTest --test-directory=tests/Feature/Portal`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Portal/DashboardController.php tests/Feature/Portal/DashboardControllerTest.php
git commit -m "feat: pass DNS detection settings from DashboardController to frontend"
```

---

### Task 6: Rewrite DnsWarningBlock Component

**Files:**
- Rewrite: `tests/js/Components/Blocks/DnsWarningBlock.spec.js`
- Rewrite: `resources/js/Components/Blocks/DnsWarningBlock.vue`

- [ ] **Step 1: Write new tests**

Replace `tests/js/Components/Blocks/DnsWarningBlock.spec.js` entirely:

```js
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';

describe('DnsWarningBlock', () => {
    let fetchMock;

    beforeEach(() => {
        vi.useFakeTimers();
        fetchMock = vi.fn();
        global.fetch = fetchMock;
        global.crypto = { randomUUID: vi.fn(() => 'test-uuid-1234') };
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    function mountBlock(props = {}) {
        return mount(DnsWarningBlock, { props });
    }

    function mockFetchResponse(server) {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ server }),
        });
    }

    function mockFetchError() {
        fetchMock.mockRejectedValueOnce(new Error('Network error'));
    }

    it('renders nothing when no checkUrl provided', () => {
        const wrapper = mountBlock({});
        expect(wrapper.text()).toBe('');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('shows warning when fetch returns server "online"', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Fix your DNS!');
    });

    it('hides warning when fetch returns server "event"', async () => {
        mockFetchResponse('event');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('treats fetch errors as pass (no warning)', async () => {
        mockFetchError();

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('replaces {uuid} in URL with a random UUID', async () => {
        mockFetchResponse('event');

        mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('https://test-uuid-1234.example.com');
    });

    it('retries every 60 seconds after failure', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(true);

        // Advance 60 seconds, trigger retry
        mockFetchResponse('event');
        vi.advanceTimersByTime(60000);
        await flushPromises();

        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('manual refresh triggers re-check', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        mockFetchResponse('event');
        await wrapper.find('[data-testid="dns-refresh"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('clears interval on unmount', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        const clearIntervalSpy = vi.spyOn(global, 'clearInterval');
        wrapper.unmount();
        expect(clearIntervalSpy).toHaveBeenCalled();
    });

    it('stops retrying after pass', async () => {
        mockFetchResponse('event');

        mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // Advancing time should NOT trigger another fetch
        vi.advanceTimersByTime(120000);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Components/Blocks/DnsWarningBlock.spec.js`
Expected: FAIL (component still has old interface)

- [ ] **Step 3: Rewrite DnsWarningBlock.vue**

Replace `resources/js/Components/Blocks/DnsWarningBlock.vue` entirely:

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    checkUrl: { type: String, default: '' },
    warningMessage: { type: String, default: '' },
});

const hasDnsIssue = ref(false);
const checking = ref(false);
let retryTimer = null;

async function checkDns() {
    if (!props.checkUrl) return;
    checking.value = true;

    try {
        const url = props.checkUrl.replace('{uuid}', crypto.randomUUID());
        const response = await fetch(url);
        const data = await response.json();

        if (data.server === 'event') {
            hasDnsIssue.value = false;
            stopRetry();
        } else {
            hasDnsIssue.value = true;
            startRetry();
        }
    } catch {
        hasDnsIssue.value = false;
        stopRetry();
    } finally {
        checking.value = false;
    }
}

function startRetry() {
    stopRetry();
    retryTimer = setInterval(checkDns, 60000);
}

function stopRetry() {
    if (retryTimer) {
        clearInterval(retryTimer);
        retryTimer = null;
    }
}

onMounted(() => {
    if (props.checkUrl) {
        checkDns();
    }
});

onUnmounted(() => {
    stopRetry();
});
</script>

<template>
    <div
        v-if="hasDnsIssue"
        data-testid="block-dns-warning"
        class="flex items-start gap-2.5 rounded border border-[var(--color-warning)]/20 bg-[var(--color-warning)]/8 px-4 py-3.5 text-[13px] text-[var(--color-warning)]"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="18"
            height="18"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="mt-px shrink-0"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
            />
        </svg>
        <div class="flex-1">{{ warningMessage }}</div>
        <button
            data-testid="dns-refresh"
            class="mt-px shrink-0 transition-colors hover:text-[var(--color-text)]"
            :class="{ 'animate-spin': checking }"
            title="Re-check DNS"
            @click="checkDns"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M20.016 4.356v4.992"
                />
            </svg>
        </button>
    </div>
</template>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run tests/js/Components/Blocks/DnsWarningBlock.spec.js`
Expected: All 9 tests PASS

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Blocks/DnsWarningBlock.vue tests/js/Components/Blocks/DnsWarningBlock.spec.js
git commit -m "feat: rewrite DnsWarningBlock for client-side fetch with retry and refresh"
```

---

### Task 7: Update Dashboard.vue to Use dnsDetection Prop

**Files:**
- Modify: `resources/js/Pages/Portal/Dashboard.vue`

- [ ] **Step 1: Update Dashboard.vue**

In `resources/js/Pages/Portal/Dashboard.vue`:

Add `dnsDetection` to props:
```js
dnsDetection: { type: Object, default: null },
```

Remove the `dnsBlock` computed:
```js
// DELETE: const dnsBlock = computed(() => props.blocks.find((b) => b.type === 'dns_warning'));
```

Replace the DNS warning template section:

Old:
```html
<!-- DNS Warning (top of page) -->
<div v-if="dnsBlock" class="mb-4">
    <DnsWarningBlock
        :has-dns-issue="true"
        :expected-dns="dnsBlock.settings?.expectedDns ?? ''"
        :actual-dns="dnsBlock.settings?.actualDns ?? ''"
        :settings="dnsBlock.settings"
    />
</div>
```

New:
```html
<!-- DNS Warning (top of page) -->
<div v-if="dnsDetection" class="mb-4">
    <DnsWarningBlock
        :check-url="dnsDetection.checkUrl"
        :warning-message="dnsDetection.warningMessage"
    />
</div>
```

- [ ] **Step 2: Run existing Dashboard JS tests**

Run: `npx vitest run tests/js/Pages/Portal/Dashboard.spec.js` (if it exists)
Expected: PASS (or skip if no JS tests for Dashboard)

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Portal/Dashboard.vue
git commit -m "feat: wire Dashboard.vue to use dnsDetection prop for DNS warning"
```

---

### Task 8: Cleanup — Remove Stale Test

**Files:**
- Modify: `tests/Unit/Config/ApertureConfigTest.php:43-46`

- [ ] **Step 1: Remove test_dns_config_removed**

In `tests/Unit/Config/ApertureConfigTest.php`, delete the `test_dns_config_removed` method (lines 43-46):

```php
// DELETE THIS:
public function test_dns_config_removed(): void
{
    $this->assertNull(config('aperture.dns'));
}
```

- [ ] **Step 2: Run the remaining tests in the file**

Run: `php artisan test --compact --filter=ApertureConfigTest`
Expected: All remaining tests PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Config/ApertureConfigTest.php
git commit -m "chore: remove stale test_dns_config_removed (DNS now uses settings table)"
```

---

### Task 9: Quality Checks and Final Verification

**Files:** All modified files

- [ ] **Step 1: Run Laravel Pint**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: All files formatted, 0 errors

- [ ] **Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

Expected: 0 errors

- [ ] **Step 3: Run Rector**

```bash
vendor/bin/rector process --dry-run
```

Expected: 0 suggestions

- [ ] **Step 4: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: All tests PASS

- [ ] **Step 5: Run ESLint**

```bash
npm run lint
```

Expected: 0 errors

- [ ] **Step 6: Run Prettier**

```bash
npm run format:check
```

Expected: All files formatted

- [ ] **Step 7: Run full JS test suite**

```bash
npx vitest run
```

Expected: All tests PASS

- [ ] **Step 8: Commit any formatting/lint fixes**

```bash
git add -A
git commit -m "chore: apply formatting and lint fixes"
```

(Only if Step 1-6 produced changes)
