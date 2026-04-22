# Admin Navigation Restructure, Settings & Content Pages — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure admin navigation into Management/Services/Content groups, remove Stats, consolidate settings, and add a Content Pages feature with a visual markdown editor.

**Architecture:** Delete unused features (Stats, Event/Portal settings), create a Page model with CRUD, build a Tiptap-based MarkdownEditor component reusable in both Pages and dashboard editor, and consolidate settings into a single GeneralSettingsController.

**Tech Stack:** Laravel 12 (PHP 8.5), Vue 3, Inertia.js, Tiptap (@tiptap/vue-3), Tailwind CSS 4, PHPUnit, Vitest

**Spec:** `docs/superpowers/specs/2026-04-22-admin-restructure-design.md`

---

## Task 1: Remove Stats Feature

**Files:**
- Delete: `app/Http/Controllers/Admin/StatsController.php`
- Delete: `resources/js/Pages/Admin/Stats/Index.vue`
- Delete: `resources/js/Pages/Admin/Stats/Bandwidth.vue`
- Delete: `tests/Feature/Admin/StatsControllerTest.php`
- Modify: `routes/web.php` — remove stats routes
- Modify: `resources/js/Components/Admin/Sidebar.vue` — remove Stats nav item

- [ ] **Step 1: Remove stats routes from web.php**

Read `routes/web.php` and remove the stats route block. The stats routes are:
```php
Route::get('/stats', [StatsController::class, 'index'])->name('stats');
Route::get('/stats/bandwidth', [StatsController::class, 'bandwidth'])->name('stats.bandwidth');
Route::get('/stats/top-talkers', [StatsController::class, 'topTalkers'])->name('stats.top-talkers');
```
Also remove the `use App\Http\Controllers\Admin\StatsController;` import at the top.

- [ ] **Step 2: Remove Stats from Sidebar.vue**

Read `resources/js/Components/Admin/Sidebar.vue` and remove the Stats item from `navGroups`. It is in the TOOLS group:
```js
{ label: 'Stats', href: '/admin/stats', icon: icons.stats },
```
Also remove the `stats` icon definition from the icons object if it exists.

- [ ] **Step 3: Delete Stats files**

```bash
rm app/Http/Controllers/Admin/StatsController.php
rm resources/js/Pages/Admin/Stats/Index.vue
rm resources/js/Pages/Admin/Stats/Bandwidth.vue
rm -r resources/js/Pages/Admin/Stats
rm tests/Feature/Admin/StatsControllerTest.php
```

- [ ] **Step 4: Verify no broken references**

```bash
php artisan route:list --name=stats 2>&1
# Expected: no routes listed
vendor/bin/phpstan analyse --no-progress 2>&1 | tail -5
# Expected: 0 errors
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: remove Stats feature

Stats section removed from admin panel — controller, Vue pages,
routes, tests, and sidebar navigation entry all deleted.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Remove Event & Portal Settings

**Files:**
- Delete: `app/Http/Controllers/Admin/EventSettingsController.php`
- Delete: `app/Http/Controllers/Admin/PortalSettingsController.php`
- Delete: `resources/js/Pages/Admin/Settings/Event.vue`
- Delete: `resources/js/Pages/Admin/Settings/Portal.vue`
- Delete: `tests/Feature/Admin/EventSettingsControllerTest.php`
- Delete: `tests/Feature/Admin/PortalSettingsControllerTest.php`
- Modify: `routes/web.php` — remove event/portal settings routes

- [ ] **Step 1: Remove routes from web.php**

Read `routes/web.php` and remove:
```php
Route::get('/settings/event', [EventSettingsController::class, 'show'])->name('settings.event');
Route::put('/settings/event', [EventSettingsController::class, 'update'])->name('settings.event.update');
Route::get('/settings/portal', [PortalSettingsController::class, 'show'])->name('settings.portal');
Route::put('/settings/portal', [PortalSettingsController::class, 'update'])->name('settings.portal.update');
```
Also remove the `use` imports for both controllers.

- [ ] **Step 2: Delete files**

```bash
rm app/Http/Controllers/Admin/EventSettingsController.php
rm app/Http/Controllers/Admin/PortalSettingsController.php
rm resources/js/Pages/Admin/Settings/Event.vue
rm resources/js/Pages/Admin/Settings/Portal.vue
rm tests/Feature/Admin/EventSettingsControllerTest.php
rm tests/Feature/Admin/PortalSettingsControllerTest.php
```

- [ ] **Step 3: Verify**

```bash
vendor/bin/phpstan analyse --no-progress 2>&1 | tail -5
php artisan route:list --name=settings 2>&1
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor: remove Event and Portal settings

Both features were unused. Controllers, Vue pages, routes, and
tests removed. Settings will be consolidated in a new page.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Remove SettingsNav, Move site_title Out of Theme

**Files:**
- Delete: `resources/js/Components/Admin/SettingsNav.vue`
- Delete: `tests/js/Components/Admin/SettingsNav.spec.js` (if exists)
- Modify: `resources/js/Pages/Admin/Settings/Theme.vue` — remove SettingsNav wrapper, remove site_title field
- Modify: `app/Http/Controllers/Admin/ThemeSettingsController.php` — remove site_title handling
- Modify: `tests/Feature/Admin/ThemeSettingsControllerTest.php` — remove site_title tests

- [ ] **Step 1: Remove site_title from ThemeSettingsController**

Read `app/Http/Controllers/Admin/ThemeSettingsController.php`. Remove `site_title` from:
1. The props passed to Inertia::render in `show()` (the settings array)
2. The validation rules in `update()`
3. The `saveSetting()` call for site_title in `update()`

- [ ] **Step 2: Remove site_title from Theme.vue**

Read `resources/js/Pages/Admin/Settings/Theme.vue`. Remove:
1. The `site_title` field from the `useForm()` initializer
2. The `<FormField>` for site_title from the template
3. Remove the `<SettingsNav>` wrapper — replace with just a plain `<div>` since settings sub-nav is being removed. Keep the content inside.
4. Remove the `import SettingsNav` line.

- [ ] **Step 3: Update other settings pages that use SettingsNav**

Read each of these files and remove the `<SettingsNav>` wrapper, replacing with a plain container:
- `resources/js/Pages/Admin/Settings/Integrations.vue`
- `resources/js/Pages/Admin/Settings/Ipv6Detection.vue`
- `resources/js/Pages/Admin/Settings/DnsDetection.vue`

In each file: remove the `import SettingsNav` and replace `<SettingsNav>...</SettingsNav>` with just the inner content.

- [ ] **Step 4: Delete SettingsNav**

```bash
rm resources/js/Components/Admin/SettingsNav.vue
# Check if test exists
ls tests/js/Components/Admin/SettingsNav.spec.js 2>/dev/null && rm tests/js/Components/Admin/SettingsNav.spec.js
```

- [ ] **Step 5: Update ThemeSettingsController tests**

Read `tests/Feature/Admin/ThemeSettingsControllerTest.php` and remove any test methods or assertions related to `site_title`.

- [ ] **Step 6: Verify**

```bash
php artisan test --compact --filter=ThemeSettings
npx vitest run tests/js/Pages/Admin/Settings/ 2>&1 | tail -10
vendor/bin/phpstan analyse --no-progress 2>&1 | tail -5
```

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: remove SettingsNav, move site_title out of Theme

SettingsNav sub-navigation removed — Services items will be promoted
to sidebar, Theme/Settings move under Content group. site_title moves
to the new consolidated Settings page.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Restructure Sidebar Navigation

**Files:**
- Modify: `resources/js/Components/Admin/Sidebar.vue`
- Modify: `tests/js/Components/Admin/Sidebar.spec.js` (if exists)

- [ ] **Step 1: Write failing test for new nav structure**

Read existing Sidebar tests (if any). Create/update `tests/js/Components/Admin/Sidebar.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Sidebar from '@/Components/Admin/Sidebar.vue';

// Mock Ziggy route helper
vi.stubGlobal('route', (name) => `/${name.replace(/\./g, '/')}`);

describe('Sidebar', () => {
    it('renders Management group with correct items', () => {
        const wrapper = mount(Sidebar, {
            global: { stubs: { Link: { template: '<a><slot /></a>', props: ['href'] } } },
        });
        const html = wrapper.html();
        expect(html).toContain('MANAGEMENT');
        expect(html).toContain('Dashboard');
        expect(html).toContain('Users');
        expect(html).toContain('IP Addresses');
        expect(html).toContain('Switches');
        expect(html).toContain('DHCP');
    });

    it('renders Services group with correct items', () => {
        const wrapper = mount(Sidebar, {
            global: { stubs: { Link: { template: '<a><slot /></a>', props: ['href'] } } },
        });
        const html = wrapper.html();
        expect(html).toContain('SERVICES');
        expect(html).toContain('Integrations');
        expect(html).toContain('IPv6 Detection');
        expect(html).toContain('DNS Detection');
    });

    it('renders Content group with correct items', () => {
        const wrapper = mount(Sidebar, {
            global: { stubs: { Link: { template: '<a><slot /></a>', props: ['href'] } } },
        });
        const html = wrapper.html();
        expect(html).toContain('CONTENT');
        expect(html).toContain('Dashboard');
        expect(html).toContain('Pages');
        expect(html).toContain('Theme');
        expect(html).toContain('Settings');
    });

    it('does not render Stats or Overview or Tools groups', () => {
        const wrapper = mount(Sidebar, {
            global: { stubs: { Link: { template: '<a><slot /></a>', props: ['href'] } } },
        });
        const html = wrapper.html();
        expect(html).not.toContain('OVERVIEW');
        expect(html).not.toContain('TOOLS');
        expect(html).not.toContain('Stats');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npx vitest run tests/js/Components/Admin/Sidebar.spec.js 2>&1 | tail -15
```
Expected: FAIL — current nav structure doesn't match.

- [ ] **Step 3: Update Sidebar.vue nav groups**

Read `resources/js/Components/Admin/Sidebar.vue` and replace the `navGroups` array:

```js
const navGroups = [
    {
        label: 'MANAGEMENT',
        items: [
            { label: 'Dashboard', href: '/admin', icon: icons.dashboard },
            { label: 'Users', href: '/admin/users', icon: icons.users },
            { label: 'IP Addresses', href: '/admin/ips', icon: icons.ips },
            { label: 'Switches', href: '/admin/switches', icon: icons.switches },
            { label: 'DHCP', href: '/admin/dhcp', icon: icons.dhcp },
        ],
    },
    {
        label: 'SERVICES',
        items: [
            { label: 'Integrations', href: '/admin/settings/integrations', icon: icons.settings },
            { label: 'IPv6 Detection', href: '/admin/settings/ipv6-detection', icon: icons.settings },
            { label: 'DNS Detection', href: '/admin/settings/dns-detection', icon: icons.settings },
        ],
    },
    {
        label: 'CONTENT',
        items: [
            { label: 'Dashboard', href: '/admin/content', icon: icons.content },
            { label: 'Pages', href: '/admin/content/pages', icon: icons.content },
            { label: 'Theme', href: '/admin/settings/theme', icon: icons.settings },
            { label: 'Settings', href: '/admin/content/settings', icon: icons.settings },
        ],
    },
];
```

Remove the old OVERVIEW and TOOLS groups. Remove the Stats icon if it exists. Add any missing icon keys (reuse existing icons as appropriate).

- [ ] **Step 4: Run test to verify it passes**

```bash
npx vitest run tests/js/Components/Admin/Sidebar.spec.js 2>&1 | tail -15
```
Expected: PASS

- [ ] **Step 5: Lint and commit**

```bash
npx eslint resources/js/Components/Admin/Sidebar.vue --fix
npx prettier --write resources/js/Components/Admin/Sidebar.vue
npx prettier --write tests/js/Components/Admin/Sidebar.spec.js
git add resources/js/Components/Admin/Sidebar.vue tests/js/Components/Admin/Sidebar.spec.js
git commit -m "refactor: restructure admin sidebar into Management/Services/Content

Navigation reorganised into three logical groups. Dashboard moved into
Management, Services items promoted from settings sub-nav, Content
group holds dashboard editor, pages, theme, and settings.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Create Page Model, Migration & Factory

**Files:**
- Create: `app/Models/Page.php`
- Create: `database/migrations/XXXX_create_pages_table.php`
- Create: `database/factories/PageFactory.php`
- Create: `tests/Unit/Models/PageTest.php`

- [ ] **Step 1: Write failing test for Page model**

Create `tests/Unit/Models/PageTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_can_be_created_with_required_fields(): void
    {
        $page = Page::create([
            'title' => 'Terms and Conditions',
            'slug' => 'terms',
            'content' => '# Terms',
        ]);

        $this->assertDatabaseHas('pages', [
            'title' => 'Terms and Conditions',
            'slug' => 'terms',
        ]);
        $this->assertEquals('# Terms', $page->content);
    }

    public function test_slug_must_be_unique(): void
    {
        Page::create(['title' => 'Page 1', 'slug' => 'test']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Page::create(['title' => 'Page 2', 'slug' => 'test']);
    }

    public function test_content_is_nullable(): void
    {
        $page = Page::create(['title' => 'Empty', 'slug' => 'empty']);
        $this->assertNull($page->content);
    }

    public function test_factory_creates_valid_page(): void
    {
        $page = Page::factory()->create();
        $this->assertNotNull($page->title);
        $this->assertNotNull($page->slug);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=PageTest
```
Expected: FAIL — model and table don't exist.

- [ ] **Step 3: Create migration**

```bash
php artisan make:migration create_pages_table --no-interaction
```

Edit the new migration file:

```php
public function up(): void
{
    Schema::create('pages', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('slug')->unique();
        $table->text('content')->nullable();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('pages');
}
```

- [ ] **Step 4: Create Page model**

Create `app/Models/Page.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    /** @use HasFactory<\Database\Factories\PageFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'content',
    ];
}
```

- [ ] **Step 5: Create PageFactory**

Create `database/factories/PageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Page> */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => ucfirst($title),
            'slug' => \Illuminate\Support\Str::slug($title),
            'content' => fake()->paragraphs(3, true),
        ];
    }

    public function terms(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => 'Terms and Conditions',
            'slug' => 'terms',
            'content' => '# Terms and Conditions',
        ]);
    }

    public function privacy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => 'Privacy Policy',
            'slug' => 'privacy',
            'content' => '# Privacy Policy',
        ]);
    }
}
```

- [ ] **Step 6: Run migration and tests**

```bash
php artisan migrate
php artisan test --compact --filter=PageTest
```
Expected: PASS — all 4 tests.

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Models/Page.php database/factories/PageFactory.php --no-progress 2>&1 | tail -5
git add app/Models/Page.php database/migrations/*create_pages* database/factories/PageFactory.php tests/Unit/Models/PageTest.php
git commit -m "feat: add Page model, migration, and factory

Pages table with title, slug (unique), and nullable content columns.
Factory includes terms() and privacy() state helpers.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 6: Create PageController (Admin CRUD)

**Files:**
- Create: `app/Http/Controllers/Admin/PageController.php`
- Create: `tests/Feature/Admin/PageControllerTest.php`
- Modify: `routes/web.php` — add page routes

- [ ] **Step 1: Write failing tests for PageController**

Create `tests/Feature/Admin/PageControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageControllerTest extends TestCase
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

    public function test_admin_can_view_pages_list(): void
    {
        $admin = $this->createAdminUser();
        Page::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/content/pages');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Pages/Index')
            ->has('pages', 3)
        );
    }

    public function test_admin_can_view_create_page_form(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/pages/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Content/Pages/Create'));
    }

    public function test_admin_can_create_page(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Terms and Conditions',
            'slug' => 'terms',
            'content' => '# Terms',
        ]);

        $response->assertRedirect('/admin/content/pages');
        $this->assertDatabaseHas('pages', ['slug' => 'terms', 'title' => 'Terms and Conditions']);
    }

    public function test_admin_can_view_edit_page_form(): void
    {
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($admin)->get("/admin/content/pages/{$page->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p
            ->component('Admin/Content/Pages/Edit')
            ->where('page.id', $page->id)
        );
    }

    public function test_admin_can_update_page(): void
    {
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/content/pages/{$page->id}", [
            'title' => 'Updated Title',
            'slug' => 'updated-slug',
            'content' => '# Updated',
        ]);

        $response->assertRedirect('/admin/content/pages');
        $this->assertDatabaseHas('pages', ['id' => $page->id, 'title' => 'Updated Title']);
    }

    public function test_admin_can_delete_page(): void
    {
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($admin)->delete("/admin/content/pages/{$page->id}");

        $response->assertRedirect('/admin/content/pages');
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_slug_must_be_unique_on_create(): void
    {
        $admin = $this->createAdminUser();
        Page::factory()->create(['slug' => 'terms']);

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Another Terms',
            'slug' => 'terms',
            'content' => 'Duplicate',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_slug_must_be_unique_on_update_except_self(): void
    {
        $admin = $this->createAdminUser();
        Page::factory()->create(['slug' => 'terms']);
        $page = Page::factory()->create(['slug' => 'other']);

        $response = $this->actingAs($admin)->put("/admin/content/pages/{$page->id}", [
            'title' => 'Other',
            'slug' => 'terms',
            'content' => '',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_title_is_required(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => '',
            'slug' => 'test',
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_slug_is_required(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Test',
            'slug' => '',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_slug_must_be_alpha_dash(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/admin/content/pages', [
            'title' => 'Test',
            'slug' => 'not valid slug!',
        ]);

        $response->assertSessionHasErrors('slug');
    }

    public function test_non_admin_cannot_access_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/content/pages')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=PageControllerTest
```
Expected: FAIL — controller and routes don't exist.

- [ ] **Step 3: Create PageController**

Create `app/Http/Controllers/Admin/PageController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function index(): Response
    {
        $pages = Page::orderBy('updated_at', 'desc')->get();

        return Inertia::render('Admin/Content/Pages/Index', [
            'pages' => $pages,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Content/Pages/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:pages,slug',
            'content' => 'nullable|string|max:65535',
        ]);

        Page::create($validated);

        return redirect()->route('admin.content.pages.index')
            ->with('success', 'Page created.');
    }

    public function edit(Page $page): Response
    {
        return Inertia::render('Admin/Content/Pages/Edit', [
            'page' => $page,
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => "required|string|max:255|alpha_dash|unique:pages,slug,{$page->id}",
            'content' => 'nullable|string|max:65535',
        ]);

        $page->update($validated);

        return redirect()->route('admin.content.pages.index')
            ->with('success', 'Page updated.');
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();

        return redirect()->route('admin.content.pages.index')
            ->with('success', 'Page deleted.');
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, inside the admin middleware group, add:

```php
use App\Http\Controllers\Admin\PageController;

Route::resource('content/pages', PageController::class)
    ->except(['show'])
    ->names('content.pages');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PageControllerTest
```
Expected: PASS — all 12 tests. (Some may still fail because Vue pages don't exist yet — that's fine, the Inertia rendering won't error but component name assertions will.)

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Http/Controllers/Admin/PageController.php --no-progress 2>&1 | tail -5
git add app/Http/Controllers/Admin/PageController.php tests/Feature/Admin/PageControllerTest.php routes/web.php
git commit -m "feat: add PageController with admin CRUD

Full CRUD for content pages with validation (unique slug, alpha_dash,
required title). Routes registered as admin.content.pages resource.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 7: Create GeneralSettingsController

**Files:**
- Create: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Create: `tests/Feature/Admin/GeneralSettingsControllerTest.php`
- Modify: `routes/web.php` — add settings routes

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Admin/GeneralSettingsControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralSettingsControllerTest extends TestCase
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

    public function test_admin_can_view_settings(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Settings')
            ->has('settings')
            ->has('pages')
        );
    }

    public function test_settings_includes_existing_pages(): void
    {
        $admin = $this->createAdminUser();
        Page::factory()->count(2)->create();

        $response = $this->actingAs($admin)->get('/admin/content/settings');

        $response->assertInertia(fn ($page) => $page->has('pages', 2));
    }

    public function test_admin_can_save_site_title(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'My Network',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => '',
            'privacy_type' => 'url',
            'privacy_value' => '',
        ]);

        $this->assertEquals('My Network', Setting::get('site_title'));
    }

    public function test_admin_can_save_dns_filtering_default(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Test',
            'dns_filtering_default' => true,
            'terms_type' => 'url',
            'terms_value' => '',
            'privacy_type' => 'url',
            'privacy_value' => '',
        ]);

        $this->assertTrue((bool) Setting::get('dns_filtering_default'));
    }

    public function test_admin_can_save_terms_as_page(): void
    {
        $admin = $this->createAdminUser();
        $page = Page::factory()->terms()->create();

        $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Test',
            'dns_filtering_default' => false,
            'terms_type' => 'page',
            'terms_value' => $page->slug,
            'privacy_type' => 'url',
            'privacy_value' => '',
        ]);

        $this->assertEquals('page', Setting::get('terms_type'));
        $this->assertEquals('terms', Setting::get('terms_value'));
    }

    public function test_admin_can_save_terms_as_url(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Test',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => 'https://example.com/terms',
            'privacy_type' => 'url',
            'privacy_value' => '',
        ]);

        $this->assertEquals('url', Setting::get('terms_type'));
        $this->assertEquals('https://example.com/terms', Setting::get('terms_value'));
    }

    public function test_site_title_is_required(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => '',
            'dns_filtering_default' => false,
            'terms_type' => 'url',
            'terms_value' => '',
            'privacy_type' => 'url',
            'privacy_value' => '',
        ]);

        $response->assertSessionHasErrors('site_title');
    }

    public function test_terms_type_must_be_valid(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/content/settings', [
            'site_title' => 'Test',
            'dns_filtering_default' => false,
            'terms_type' => 'invalid',
            'terms_value' => '',
            'privacy_type' => 'url',
            'privacy_value' => '',
        ]);

        $response->assertSessionHasErrors('terms_type');
    }

    public function test_non_admin_cannot_access_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/content/settings')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=GeneralSettingsControllerTest
```

- [ ] **Step 3: Create GeneralSettingsController**

Create `app/Http/Controllers/Admin/GeneralSettingsController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Content/Settings', [
            'settings' => [
                'site_title' => Setting::get('site_title', 'Aperture'),
                'dns_filtering_default' => (bool) Setting::get('dns_filtering_default', false),
                'terms_type' => Setting::get('terms_type', 'url'),
                'terms_value' => Setting::get('terms_value', ''),
                'privacy_type' => Setting::get('privacy_type', 'url'),
                'privacy_value' => Setting::get('privacy_value', ''),
            ],
            'pages' => Page::orderBy('title')->get(['id', 'title', 'slug']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_title' => 'required|string|max:255',
            'dns_filtering_default' => 'required|boolean',
            'terms_type' => 'required|in:page,url',
            'terms_value' => 'nullable|string|max:500',
            'privacy_type' => 'required|in:page,url',
            'privacy_value' => 'nullable|string|max:500',
        ]);

        $this->saveSetting('site_title', 'Site Title', $validated['site_title']);
        $this->saveSetting('dns_filtering_default', 'DNS Filtering Default', $validated['dns_filtering_default']);
        $this->saveSetting('terms_type', 'Terms Type', $validated['terms_type']);
        $this->saveSetting('terms_value', 'Terms Value', $validated['terms_value'] ?? '');
        $this->saveSetting('privacy_type', 'Privacy Type', $validated['privacy_type']);
        $this->saveSetting('privacy_value', 'Privacy Value', $validated['privacy_value'] ?? '');

        return redirect()->route('admin.content.settings')
            ->with('success', 'Settings saved.');
    }

    protected function saveSetting(string $code, string $name, mixed $value): void
    {
        $setting = Setting::whereCode($code)->first();

        if ($setting) {
            $setting->value = $value;
            $setting->save();
        } else {
            $setting = new Setting;
            $setting->code = $code;
            $setting->name = $name;
            $setting->value = $value;
            $setting->save();
        }
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, inside the admin middleware group, add:

```php
use App\Http\Controllers\Admin\GeneralSettingsController;

Route::get('/content/settings', [GeneralSettingsController::class, 'show'])->name('content.settings');
Route::put('/content/settings', [GeneralSettingsController::class, 'update'])->name('content.settings.update');
```

Ensure these are placed **before** the `content/pages` resource route to avoid route conflicts.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=GeneralSettingsControllerTest
```
Expected: PASS

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Http/Controllers/Admin/GeneralSettingsController.php --no-progress 2>&1 | tail -5
git add app/Http/Controllers/Admin/GeneralSettingsController.php tests/Feature/Admin/GeneralSettingsControllerTest.php routes/web.php
git commit -m "feat: add GeneralSettingsController with consolidated settings

Manages site_title, dns_filtering_default, terms (page/url selector),
and privacy (page/url selector). Replaces Event + Portal controllers.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 8: Create Public Page Route

**Files:**
- Create: `app/Http/Controllers/PageViewController.php`
- Create: `tests/Feature/PageViewTest.php`
- Modify: `routes/web.php` — add public route

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/PageViewTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_can_be_viewed(): void
    {
        Page::factory()->create([
            'title' => 'Terms',
            'slug' => 'terms',
            'content' => '# Terms and Conditions',
        ]);

        $response = $this->get('/content/terms');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Content/Show')
            ->where('page.slug', 'terms')
            ->where('page.title', 'Terms')
        );
    }

    public function test_public_page_returns_404_for_missing_slug(): void
    {
        $response = $this->get('/content/nonexistent');
        $response->assertNotFound();
    }

    public function test_public_page_accessible_while_logged_out(): void
    {
        Page::factory()->create(['slug' => 'terms']);

        $response = $this->get('/content/terms');
        $response->assertOk();
    }

    public function test_public_page_accessible_while_logged_in(): void
    {
        $user = User::factory()->create();
        Page::factory()->create(['slug' => 'terms']);

        $response = $this->actingAs($user)->get('/content/terms');
        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=PageViewTest
```

- [ ] **Step 3: Create PageViewController**

Create `app/Http/Controllers/PageViewController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

class PageViewController extends Controller
{
    public function show(string $slug): Response
    {
        $page = Page::where('slug', $slug)->firstOrFail();

        return Inertia::render('Content/Show', [
            'page' => $page,
        ]);
    }
}
```

- [ ] **Step 4: Add public route**

In `routes/web.php`, **outside** the admin middleware group (in the public routes area):

```php
use App\Http\Controllers\PageViewController;

Route::get('/content/{slug}', [PageViewController::class, 'show'])->name('content.show');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PageViewTest
```
Expected: PASS

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PageViewController.php tests/Feature/PageViewTest.php routes/web.php
git commit -m "feat: add public page view route at /content/{slug}

Pages accessible without authentication. Uses Inertia rendering
with portal layout. Returns 404 for missing slugs.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 9: Share appName via Inertia Middleware & Update AppLogo

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/Components/AppLogo.vue`
- Modify: `tests/js/Components/AppLogo.spec.js` (if exists)

- [ ] **Step 1: Add appName to shared Inertia props**

Read `app/Http/Middleware/HandleInertiaRequests.php`. Add `appName` to the shared array:

```php
'appName' => fn (): string => (string) Setting::get('site_title', 'Aperture'),
```

Add the import: `use App\Models\Setting;`

- [ ] **Step 2: Update AppLogo.vue — remove icon**

Read `resources/js/Components/AppLogo.vue`. Replace entire template:

```vue
<template>
    <span
        class="font-heading text-lg font-bold text-[var(--color-text)]"
        data-testid="app-logo"
    >
        {{ appName }}
    </span>
</template>
```

Keep the existing `<script setup>` — it already reads `appName` from page props with "Aperture" fallback.

- [ ] **Step 3: Write/update AppLogo test**

Create or update `tests/js/Components/AppLogo.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppLogo from '@/Components/AppLogo.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { appName: 'My Network' } }),
}));

describe('AppLogo', () => {
    it('renders site title from page props', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.text()).toBe('My Network');
    });

    it('does not render an SVG icon', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it('has data-testid', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.find('[data-testid="app-logo"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 4: Run tests**

```bash
npx vitest run tests/js/Components/AppLogo.spec.js 2>&1 | tail -10
php artisan test --compact --filter=HandleInertia 2>&1 | tail -10
```

- [ ] **Step 5: Lint and commit**

```bash
npx eslint resources/js/Components/AppLogo.vue --fix
npx prettier --write resources/js/Components/AppLogo.vue tests/js/Components/AppLogo.spec.js
vendor/bin/pint --dirty --format agent
git add resources/js/Components/AppLogo.vue app/Http/Middleware/HandleInertiaRequests.php tests/js/Components/AppLogo.spec.js
git commit -m "feat: use configurable site title in portal header

AppLogo now shows only the site title text (no SVG icon). Title
comes from site_title setting shared via Inertia middleware.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 10: Install Tiptap & Create MarkdownEditor Component

**Files:**
- Create: `resources/js/Components/UI/MarkdownEditor.vue`
- Create: `tests/js/Components/UI/MarkdownEditor.spec.js`

- [ ] **Step 1: Install Tiptap dependencies**

```bash
npm install @tiptap/vue-3 @tiptap/starter-kit @tiptap/extension-link @tiptap/extension-underline @tiptap/extension-placeholder
```

- [ ] **Step 2: Write failing test for MarkdownEditor**

Create `tests/js/Components/UI/MarkdownEditor.spec.js`:

```js
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
```

- [ ] **Step 3: Run test to verify it fails**

```bash
npx vitest run tests/js/Components/UI/MarkdownEditor.spec.js 2>&1 | tail -15
```

- [ ] **Step 4: Create MarkdownEditor.vue**

Create `resources/js/Components/UI/MarkdownEditor.vue`:

```vue
<script setup>
import { ref, watch, onBeforeUnmount, computed } from 'vue';
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

const editor = useEditor({
    content: markdownToHtml(props.modelValue),
    extensions: [
        StarterKit.configure({
            heading: { levels: [1, 2, 3] },
        }),
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
            'data-testid': 'editor-visual',
        },
    },
});

function switchMode(newMode) {
    if (newMode === mode.value) return;

    if (newMode === 'source') {
        sourceContent.value = htmlToMarkdown(editor.value.getHTML());
    } else {
        editor.value.commands.setContent(markdownToHtml(sourceContent.value));
    }
    mode.value = newMode;
}

function onSourceInput(event) {
    sourceContent.value = event.target.value;
    emit('update:modelValue', event.target.value);
}

watch(
    () => props.modelValue,
    (val) => {
        if (mode.value === 'visual' && editor.value && !editor.value.isFocused) {
            editor.value.commands.setContent(markdownToHtml(val));
        }
        if (mode.value === 'source') {
            sourceContent.value = val;
        }
    },
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});

// Simple markdown <-> HTML conversion helpers
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
        .replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>')
        .replace(/\n\n/g, '</p><p>')
        .replace(/^(?!<[h|u|o|l|p|hr])(.+)$/gm, '<p>$1</p>');
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

const toolbarButtons = computed(() => [
    { key: 'bold', label: 'B', testid: 'toolbar-bold', style: 'font-weight: 700', action: () => editor.value?.chain().focus().toggleBold().run(), isActive: () => editor.value?.isActive('bold') },
    { key: 'italic', label: 'I', testid: 'toolbar-italic', style: 'font-style: italic', action: () => editor.value?.chain().focus().toggleItalic().run(), isActive: () => editor.value?.isActive('italic') },
    { key: 'underline', label: 'U', testid: 'toolbar-underline', style: 'text-decoration: underline', action: () => editor.value?.chain().focus().toggleUnderline().run(), isActive: () => editor.value?.isActive('underline') },
    { key: 'strike', label: 'S', testid: 'toolbar-strike', style: 'text-decoration: line-through', action: () => editor.value?.chain().focus().toggleStrike().run(), isActive: () => editor.value?.isActive('strike') },
    { key: 'sep1' },
    { key: 'h1', label: 'H1', testid: 'toolbar-h1', action: () => editor.value?.chain().focus().toggleHeading({ level: 1 }).run(), isActive: () => editor.value?.isActive('heading', { level: 1 }) },
    { key: 'h2', label: 'H2', testid: 'toolbar-h2', action: () => editor.value?.chain().focus().toggleHeading({ level: 2 }).run(), isActive: () => editor.value?.isActive('heading', { level: 2 }) },
    { key: 'h3', label: 'H3', testid: 'toolbar-h3', action: () => editor.value?.chain().focus().toggleHeading({ level: 3 }).run(), isActive: () => editor.value?.isActive('heading', { level: 3 }) },
    { key: 'sep2' },
    { key: 'bulletList', label: '\u2022 List', testid: 'toolbar-bullet-list', action: () => editor.value?.chain().focus().toggleBulletList().run(), isActive: () => editor.value?.isActive('bulletList') },
    { key: 'orderedList', label: '1. List', testid: 'toolbar-ordered-list', action: () => editor.value?.chain().focus().toggleOrderedList().run(), isActive: () => editor.value?.isActive('orderedList') },
    { key: 'blockquote', label: 'Quote', testid: 'toolbar-blockquote', action: () => editor.value?.chain().focus().toggleBlockquote().run(), isActive: () => editor.value?.isActive('blockquote') },
    { key: 'sep3' },
    { key: 'link', label: 'Link', testid: 'toolbar-link', action: () => { const url = window.prompt('URL:'); if (url) editor.value?.chain().focus().setLink({ href: url }).run(); }, isActive: () => editor.value?.isActive('link') },
    { key: 'code', label: 'Code', testid: 'toolbar-code', action: () => editor.value?.chain().focus().toggleCodeBlock().run(), isActive: () => editor.value?.isActive('codeBlock') },
    { key: 'hr', label: '\u2014', testid: 'toolbar-hr', action: () => editor.value?.chain().focus().setHorizontalRule().run() },
]);
</script>

<template>
    <div data-testid="markdown-editor">
        <div class="flex items-center justify-between" data-testid="editor-header">
            <slot name="label" />
            <div
                class="flex gap-0.5 rounded-md bg-[var(--color-surface-alt)] p-0.5"
                data-testid="editor-mode-toggle"
            >
                <button
                    data-testid="editor-mode-visual"
                    class="rounded px-3 py-1 text-[11px] font-semibold transition-colors"
                    :class="
                        mode === 'visual'
                            ? 'bg-[var(--color-primary)] text-[var(--color-accent-text)]'
                            : 'text-[var(--color-text-secondary)]'
                    "
                    type="button"
                    @click="switchMode('visual')"
                >
                    Visual
                </button>
                <button
                    data-testid="editor-mode-source"
                    class="rounded px-3 py-1 text-[11px] font-semibold transition-colors"
                    :class="
                        mode === 'source'
                            ? 'bg-[var(--color-primary)] text-[var(--color-accent-text)]'
                            : 'text-[var(--color-text-secondary)]'
                    "
                    type="button"
                    @click="switchMode('source')"
                >
                    Source
                </button>
            </div>
        </div>
        <div
            class="mt-1 overflow-hidden rounded-md border border-[var(--color-border)]"
            data-testid="editor-container"
        >
            <div
                v-if="mode === 'visual'"
                class="flex flex-wrap items-center gap-0.5 border-b border-[var(--color-border)] bg-[var(--color-surface-alt)] px-2 py-1.5"
                data-testid="editor-toolbar"
            >
                <template v-for="btn in toolbarButtons" :key="btn.key">
                    <span
                        v-if="btn.key.startsWith('sep')"
                        class="mx-1 h-4 w-px bg-[var(--color-border)]"
                    />
                    <button
                        v-else
                        :data-testid="btn.testid"
                        class="rounded px-2 py-1 text-[12px] transition-colors"
                        :class="
                            btn.isActive?.()
                                ? 'bg-[var(--color-surface-hover)] text-[var(--color-text)]'
                                : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text)]'
                        "
                        :style="btn.style || ''"
                        type="button"
                        @click="btn.action"
                    >
                        {{ btn.label }}
                    </button>
                </template>
            </div>
            <div
                :style="{ minHeight: height }"
                data-testid="editor-content-area"
            >
                <EditorContent
                    v-if="mode === 'visual'"
                    :editor="editor"
                    class="h-full bg-[var(--color-input-bg)] p-4 text-[13px] text-[var(--color-text)]"
                />
                <textarea
                    v-else
                    :value="sourceContent"
                    data-testid="editor-source"
                    class="h-full w-full resize-none bg-[var(--color-input-bg)] p-4 font-mono text-[13px] text-[var(--color-text)] focus:outline-none"
                    :style="{ minHeight: height }"
                    @input="onSourceInput"
                />
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 5: Run tests**

```bash
npx vitest run tests/js/Components/UI/MarkdownEditor.spec.js 2>&1 | tail -15
```
Expected: PASS

- [ ] **Step 6: Lint and commit**

```bash
npx eslint resources/js/Components/UI/MarkdownEditor.vue --fix
npx prettier --write resources/js/Components/UI/MarkdownEditor.vue tests/js/Components/UI/MarkdownEditor.spec.js
git add resources/js/Components/UI/MarkdownEditor.vue tests/js/Components/UI/MarkdownEditor.spec.js package.json package-lock.json
git commit -m "feat: add MarkdownEditor component with Tiptap

Visual/Source toggle, rich toolbar (bold, italic, underline, strike,
H1-H3, lists, blockquote, link, code, HR). Built on Tiptap with
markdown <-> HTML conversion. Reusable in pages editor and dashboard.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 11: Create Admin Pages Vue Pages (Index, Create, Edit)

**Files:**
- Create: `resources/js/Pages/Admin/Content/Pages/Index.vue`
- Create: `resources/js/Pages/Admin/Content/Pages/Create.vue`
- Create: `resources/js/Pages/Admin/Content/Pages/Edit.vue`
- Create: `tests/js/Pages/Admin/Content/Pages/Index.spec.js`
- Create: `tests/js/Pages/Admin/Content/Pages/Edit.spec.js`

- [ ] **Step 1: Write failing tests for Index page**

Create `tests/js/Pages/Admin/Content/Pages/Index.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '@/Pages/Admin/Content/Pages/Index.vue';

vi.stubGlobal('route', (name, params) => {
    if (name === 'admin.content.pages.edit') return `/admin/content/pages/${params}/edit`;
    if (name === 'admin.content.pages.create') return '/admin/content/pages/create';
    return `/${name}`;
});

const AdminLayout = { template: '<div><slot /></div>' };

describe('Pages Index', () => {
    const pages = [
        { id: 1, title: 'Terms', slug: 'terms', updated_at: '2026-04-22T10:00:00Z' },
        { id: 2, title: 'Privacy', slug: 'privacy', updated_at: '2026-04-20T10:00:00Z' },
    ];

    it('renders page list', () => {
        const wrapper = mount(Index, {
            props: { pages },
            global: { stubs: { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } } },
        });
        expect(wrapper.text()).toContain('Terms');
        expect(wrapper.text()).toContain('Privacy');
    });

    it('shows slug with /content/ prefix', () => {
        const wrapper = mount(Index, {
            props: { pages },
            global: { stubs: { Link: { template: '<a><slot /></a>', props: ['href'] } } },
        });
        expect(wrapper.text()).toContain('/content/terms');
    });

    it('shows empty state when no pages', () => {
        const wrapper = mount(Index, {
            props: { pages: [] },
            global: { stubs: { Link: { template: '<a><slot /></a>', props: ['href'] } } },
        });
        expect(wrapper.text()).toContain('No pages yet');
    });

    it('has new page button', () => {
        const wrapper = mount(Index, {
            props: { pages },
            global: { stubs: { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } } },
        });
        expect(wrapper.find('[data-testid="action-new-page"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Create Index.vue**

Create `resources/js/Pages/Admin/Content/Pages/Index.vue`:

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    pages: { type: Array, default: () => [] },
});

function timeAgo(dateString) {
    const seconds = Math.floor((Date.now() - new Date(dateString).getTime()) / 1000);
    const intervals = [
        { label: 'year', seconds: 31536000 },
        { label: 'month', seconds: 2592000 },
        { label: 'week', seconds: 604800 },
        { label: 'day', seconds: 86400 },
        { label: 'hour', seconds: 3600 },
        { label: 'minute', seconds: 60 },
    ];
    for (const interval of intervals) {
        const count = Math.floor(seconds / interval.seconds);
        if (count >= 1) return `${count} ${interval.label}${count > 1 ? 's' : ''} ago`;
    }
    return 'just now';
}
</script>

<template>
    <div data-testid="pages-index">
        <div class="mb-6 flex items-center justify-between">
            <h1
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                data-testid="page-title"
            >
                Pages
            </h1>
            <Link
                :href="route('admin.content.pages.create')"
                class="rounded-md bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-accent-text)]"
                data-testid="action-new-page"
            >
                + New Page
            </Link>
        </div>

        <div v-if="pages.length === 0" class="py-12 text-center" data-testid="pages-empty">
            <p class="text-[14px] text-[var(--color-text-secondary)]">No pages yet</p>
            <p class="mt-1 text-[13px] text-[var(--color-text-muted)]">
                Create pages for terms, privacy policies, or any information your visitors need.
            </p>
        </div>

        <table v-else class="w-full text-[13px]" data-testid="pages-table">
            <thead>
                <tr class="border-b border-[var(--color-border)]">
                    <th
                        class="py-2 text-left font-heading text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
                    >
                        Title
                    </th>
                    <th
                        class="py-2 text-left font-heading text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
                    >
                        Slug
                    </th>
                    <th
                        class="py-2 text-left font-heading text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
                    >
                        Updated
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="page in pages"
                    :key="page.id"
                    class="cursor-pointer border-b border-[var(--color-border)]/40 transition-colors hover:bg-[var(--color-surface-hover)]"
                    :data-testid="'page-row-' + page.slug"
                    @click="$inertia.visit(route('admin.content.pages.edit', page.id))"
                >
                    <td class="py-2.5 font-medium">{{ page.title }}</td>
                    <td class="py-2.5">
                        <code
                            class="font-mono text-[12px] text-[var(--color-primary)]"
                        >/content/{{ page.slug }}</code>
                    </td>
                    <td class="py-2.5 text-[var(--color-text-muted)]" :title="page.updated_at">
                        {{ timeAgo(page.updated_at) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
```

- [ ] **Step 3: Create Create.vue**

Create `resources/js/Pages/Admin/Content/Pages/Create.vue`:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';
import { watch } from 'vue';

defineOptions({ layout: AdminLayout });

const form = useForm({
    title: '',
    slug: '',
    content: '',
});

let slugManuallyEdited = false;

function generateSlug(title) {
    return title
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}

watch(
    () => form.title,
    (title) => {
        if (!slugManuallyEdited) {
            form.slug = generateSlug(title);
        }
    },
);

function onSlugInput() {
    slugManuallyEdited = true;
}

function submit() {
    form.post(route('admin.content.pages.store'));
}
</script>

<template>
    <div data-testid="page-create">
        <h1
            class="mb-6 font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            data-testid="page-title"
        >
            New Page
        </h1>

        <form @submit.prevent="submit">
            <div class="mb-4 grid grid-cols-2 gap-4">
                <FormField label="Title" name="title" :error="form.errors.title">
                    <input
                        v-model="form.title"
                        type="text"
                        data-testid="input-title"
                        class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-[13px] text-[var(--color-text)] focus:border-[var(--color-primary)] focus:outline-none"
                    />
                </FormField>
                <FormField label="Slug" name="slug" :error="form.errors.slug">
                    <div class="flex">
                        <span
                            class="rounded-l-md border border-r-0 border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-[13px] text-[var(--color-text-muted)]"
                        >
                            /content/
                        </span>
                        <input
                            v-model="form.slug"
                            type="text"
                            data-testid="input-slug"
                            class="w-full rounded-r-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] focus:border-[var(--color-primary)] focus:outline-none"
                            @input="onSlugInput"
                        />
                    </div>
                </FormField>
            </div>

            <MarkdownEditor v-model="form.content" height="350px">
                <template #label>
                    <label
                        class="text-[12px] font-semibold text-[var(--color-text-secondary)]"
                    >Content</label>
                </template>
            </MarkdownEditor>

            <div class="mt-4 flex gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    data-testid="action-save"
                    class="rounded-md bg-[var(--color-primary)] px-5 py-2 text-[13px] font-semibold text-[var(--color-accent-text)]"
                >
                    Save Page
                </button>
                <button
                    type="button"
                    data-testid="action-cancel"
                    class="rounded-md border border-[var(--color-border)] px-5 py-2 text-[13px] text-[var(--color-text-secondary)]"
                    @click="$inertia.visit(route('admin.content.pages.index'))"
                >
                    Cancel
                </button>
            </div>
        </form>
    </div>
</template>
```

- [ ] **Step 4: Create Edit.vue**

Create `resources/js/Pages/Admin/Content/Pages/Edit.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    page: { type: Object, required: true },
});

const form = useForm({
    title: props.page.title,
    slug: props.page.slug,
    content: props.page.content ?? '',
});

const showDeleteModal = ref(false);

function submit() {
    form.put(route('admin.content.pages.update', props.page.id));
}

function confirmDelete() {
    router.delete(route('admin.content.pages.destroy', props.page.id));
}
</script>

<template>
    <div data-testid="page-edit">
        <h1
            class="mb-6 font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            data-testid="page-title"
        >
            Edit Page
        </h1>

        <form @submit.prevent="submit">
            <div class="mb-4 grid grid-cols-2 gap-4">
                <FormField label="Title" name="title" :error="form.errors.title">
                    <input
                        v-model="form.title"
                        type="text"
                        data-testid="input-title"
                        class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-[13px] text-[var(--color-text)] focus:border-[var(--color-primary)] focus:outline-none"
                    />
                </FormField>
                <FormField label="Slug" name="slug" :error="form.errors.slug">
                    <div class="flex">
                        <span
                            class="rounded-l-md border border-r-0 border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-[13px] text-[var(--color-text-muted)]"
                        >
                            /content/
                        </span>
                        <input
                            v-model="form.slug"
                            type="text"
                            data-testid="input-slug"
                            class="w-full rounded-r-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] focus:border-[var(--color-primary)] focus:outline-none"
                        />
                    </div>
                </FormField>
            </div>

            <MarkdownEditor v-model="form.content" height="350px">
                <template #label>
                    <label
                        class="text-[12px] font-semibold text-[var(--color-text-secondary)]"
                    >Content</label>
                </template>
            </MarkdownEditor>

            <div class="mt-4 flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    data-testid="action-save"
                    class="rounded-md bg-[var(--color-primary)] px-5 py-2 text-[13px] font-semibold text-[var(--color-accent-text)]"
                >
                    Save Page
                </button>
                <button
                    type="button"
                    data-testid="action-cancel"
                    class="rounded-md border border-[var(--color-border)] px-5 py-2 text-[13px] text-[var(--color-text-secondary)]"
                    @click="$inertia.visit(route('admin.content.pages.index'))"
                >
                    Cancel
                </button>
                <span class="flex-1" />
                <button
                    type="button"
                    data-testid="action-delete"
                    class="rounded-md border border-[var(--color-danger)]/30 px-4 py-2 text-[13px] text-[var(--color-danger)]"
                    @click="showDeleteModal = true"
                >
                    Delete Page
                </button>
            </div>
        </form>

        <ConfirmModal
            v-if="showDeleteModal"
            title="Delete Page"
            message="Are you sure you want to delete this page? This action cannot be undone."
            confirm-label="Delete"
            variant="danger"
            @confirm="confirmDelete"
            @cancel="showDeleteModal = false"
        />
    </div>
</template>
```

- [ ] **Step 5: Run tests**

```bash
npx vitest run tests/js/Pages/Admin/Content/Pages/ 2>&1 | tail -15
```

- [ ] **Step 6: Lint and commit**

```bash
npx eslint resources/js/Pages/Admin/Content/Pages/ --fix
npx prettier --write resources/js/Pages/Admin/Content/Pages/ tests/js/Pages/Admin/Content/Pages/
git add resources/js/Pages/Admin/Content/Pages/ tests/js/Pages/Admin/Content/Pages/
git commit -m "feat: add admin Pages list, create, and edit views

Index with table/empty state, Create with auto-slug generation,
Edit with delete confirmation modal. All use MarkdownEditor.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 12: Create Admin Settings Vue Page

**Files:**
- Create: `resources/js/Pages/Admin/Content/Settings.vue`
- Create: `tests/js/Pages/Admin/Content/Settings.spec.js`

- [ ] **Step 1: Write failing test**

Create `tests/js/Pages/Admin/Content/Settings.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Settings from '@/Pages/Admin/Content/Settings.vue';

vi.stubGlobal('route', (name) => `/${name.replace(/\./g, '/')}`);

describe('Settings Page', () => {
    const defaultProps = {
        settings: {
            site_title: 'My Network',
            dns_filtering_default: true,
            terms_type: 'url',
            terms_value: 'https://example.com/terms',
            privacy_type: 'page',
            privacy_value: 'privacy',
        },
        pages: [
            { id: 1, title: 'Terms', slug: 'terms' },
            { id: 2, title: 'Privacy', slug: 'privacy' },
        ],
    };

    it('renders site title input', () => {
        const wrapper = mount(Settings, {
            props: defaultProps,
            global: { stubs: { FormField: { template: '<div><slot /></div>', props: ['label', 'name', 'error'] } } },
        });
        expect(wrapper.find('[data-testid="input-site-title"]').exists()).toBe(true);
    });

    it('renders DNS filtering toggle', () => {
        const wrapper = mount(Settings, {
            props: defaultProps,
            global: { stubs: { FormField: { template: '<div><slot /></div>', props: ['label', 'name', 'error'] } } },
        });
        expect(wrapper.find('[data-testid="toggle-dns-filtering"]').exists()).toBe(true);
    });

    it('renders terms type selector', () => {
        const wrapper = mount(Settings, {
            props: defaultProps,
            global: { stubs: { FormField: { template: '<div><slot /></div>', props: ['label', 'name', 'error'] } } },
        });
        expect(wrapper.find('[data-testid="select-terms-type"]').exists()).toBe(true);
    });

    it('renders section headings', () => {
        const wrapper = mount(Settings, {
            props: defaultProps,
            global: { stubs: { FormField: { template: '<div><slot /></div>', props: ['label', 'name', 'error'] } } },
        });
        const text = wrapper.text();
        expect(text).toContain('Branding');
        expect(text).toContain('Network Defaults');
        expect(text).toContain('Legal');
    });
});
```

- [ ] **Step 2: Create Settings.vue**

Create `resources/js/Pages/Admin/Content/Settings.vue`. This page has three sections (Branding, Network Defaults, Legal) with the composite page/URL selector pattern for terms and privacy. The full implementation should follow the mockup from the brainstorming companion. Use `useForm` from Inertia, section headers matching the Dispatch design system (uppercase, tracking-wider, muted color, font-heading), and FormField components.

Key elements:
- `input-site-title` — text input
- `toggle-dns-filtering` — toggle switch (matching existing DnsFilterBlock toggle pattern)
- `select-terms-type` / `select-privacy-type` — select dropdown (Content Page / Custom URL)
- When type is "page", show a second select with available pages
- When type is "url", show a URL text input
- Save button submits `form.put(route('admin.content.settings.update'))`

- [ ] **Step 3: Run tests**

```bash
npx vitest run tests/js/Pages/Admin/Content/Settings.spec.js 2>&1 | tail -15
```

- [ ] **Step 4: Lint and commit**

```bash
npx eslint resources/js/Pages/Admin/Content/Settings.vue --fix
npx prettier --write resources/js/Pages/Admin/Content/Settings.vue tests/js/Pages/Admin/Content/Settings.spec.js
git add resources/js/Pages/Admin/Content/Settings.vue tests/js/Pages/Admin/Content/Settings.spec.js
git commit -m "feat: add consolidated Settings page

Three sections: Branding (site title), Network Defaults (DNS
filtering toggle), Legal (terms/privacy with page selector).

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 13: Create Public Page View

**Files:**
- Create: `resources/js/Pages/Content/Show.vue`
- Create: `tests/js/Pages/Content/Show.spec.js`

- [ ] **Step 1: Write test**

Create `tests/js/Pages/Content/Show.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '@/Pages/Content/Show.vue';

describe('Public Page View', () => {
    it('renders page title', () => {
        const wrapper = mount(Show, {
            props: { page: { title: 'Terms', slug: 'terms', content: '# Terms' } },
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Terms');
    });

    it('renders markdown content', () => {
        const wrapper = mount(Show, {
            props: { page: { title: 'Terms', slug: 'terms', content: '**Bold text**' } },
        });
        expect(wrapper.find('[data-testid="page-content"]').exists()).toBe(true);
    });

    it('uses prose class for content', () => {
        const wrapper = mount(Show, {
            props: { page: { title: 'Terms', slug: 'terms', content: 'Hello' } },
        });
        expect(wrapper.find('.prose').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Create Show.vue**

Create `resources/js/Pages/Content/Show.vue`. This page uses the PortalLayout (header + user menu), renders the page title as an h1, and the content as rendered markdown inside a `.prose` container. Install `marked` for server-safe markdown rendering:

```bash
npm install marked
```

```vue
<script setup>
import { computed } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { marked } from 'marked';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    page: { type: Object, required: true },
});

const renderedContent = computed(() => {
    return marked.parse(props.page.content || '', { breaks: true });
});
</script>

<template>
    <div class="mx-auto max-w-3xl px-6 py-8" data-testid="public-page">
        <h1
            class="mb-6 font-heading text-[28px] font-bold text-[var(--color-text)]"
            data-testid="page-title"
        >
            {{ page.title }}
        </h1>
        <div
            class="prose prose-sm max-w-none text-[var(--color-text-secondary)]"
            data-testid="page-content"
            v-html="renderedContent"
        />
    </div>
</template>
```

- [ ] **Step 3: Run tests**

```bash
npx vitest run tests/js/Pages/Content/Show.spec.js 2>&1 | tail -10
```

- [ ] **Step 4: Lint and commit**

```bash
npx eslint resources/js/Pages/Content/Show.vue --fix
npx prettier --write resources/js/Pages/Content/Show.vue tests/js/Pages/Content/Show.spec.js
git add resources/js/Pages/Content/Show.vue tests/js/Pages/Content/Show.spec.js package.json package-lock.json
git commit -m "feat: add public page view at /content/{slug}

Renders markdown content in portal layout with prose styling.
Accessible with or without authentication.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 14: Update EditorSidePanel to Use MarkdownEditor

**Files:**
- Modify: `resources/js/Components/Admin/Content/EditorSidePanel.vue`
- Modify: `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`

- [ ] **Step 1: Read current EditorSidePanel.vue**

Read the file to understand the current textarea usage for `custom_markdown` content editing.

- [ ] **Step 2: Replace textarea with MarkdownEditor for markdown types**

Import MarkdownEditor and replace the `<textarea>` used for `custom_markdown` content with `<MarkdownEditor v-model="content" height="200px" />`. Keep the template variable insertion logic working — it can append to the content string.

Add import:
```js
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';
```

Replace the content textarea (for `textTypes` that include `custom_markdown`) with:
```vue
<MarkdownEditor v-model="content" height="200px">
    <template #label>
        <label class="text-[12px] font-semibold text-[var(--color-text-secondary)]">Content</label>
    </template>
</MarkdownEditor>
```

- [ ] **Step 3: Update tests**

Read `tests/js/Components/Admin/Content/EditorSidePanel.spec.js` and update any tests that reference the old textarea to work with the MarkdownEditor component.

- [ ] **Step 4: Run tests**

```bash
npx vitest run tests/js/Components/Admin/Content/EditorSidePanel.spec.js 2>&1 | tail -15
```

- [ ] **Step 5: Lint and commit**

```bash
npx eslint resources/js/Components/Admin/Content/EditorSidePanel.vue --fix
npx prettier --write resources/js/Components/Admin/Content/EditorSidePanel.vue
git add resources/js/Components/Admin/Content/EditorSidePanel.vue tests/js/Components/Admin/Content/EditorSidePanel.spec.js
git commit -m "refactor: use MarkdownEditor in dashboard editor side panel

Replaces plain textarea for custom_markdown blocks with the
Tiptap-based MarkdownEditor component for consistent editing.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Task 15: Final Integration — Build, Test Suite, Lint

**Files:** All modified files from previous tasks

- [ ] **Step 1: Run full PHP test suite**

```bash
php artisan test --compact
```
Expected: All tests pass. Fix any failures.

- [ ] **Step 2: Run full JS test suite**

```bash
npx vitest run 2>&1 | tail -20
```
Expected: All tests pass.

- [ ] **Step 3: Run all linters**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --no-progress 2>&1 | tail -5
npx eslint resources/js/ --ext .vue,.js 2>&1 | tail -10
npx prettier --check resources/js/ 2>&1 | tail -10
```

- [ ] **Step 4: Build frontend**

```bash
npm run build
```

- [ ] **Step 5: Manual verification**

Start the dev server and verify in browser:
1. Admin sidebar shows Management/Services/Content groups
2. Stats is gone
3. Content > Pages shows list/create/edit
4. Content > Settings shows consolidated settings
5. Theme page no longer has site_title field
6. Portal header shows configured site title (no icon)
7. Public page at /content/{slug} renders with portal layout
8. Services items (Integrations, IPv6, DNS Detection) work from sidebar

- [ ] **Step 6: Commit any final fixes**

```bash
git add -A
git commit -m "chore: final integration fixes and build

All tests passing, linters clean, frontend built.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```
