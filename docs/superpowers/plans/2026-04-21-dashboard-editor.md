# Dashboard Content & Layout Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins full CRUD control over dashboard content blocks and a visual 2D grid editor for positioning and resizing them.

**Architecture:** ContentBlock model gains grid coordinates (col, row, col_span, row_span). A new grid editor page lets admins drag, drop, and resize blocks on a 3-column CSS Grid. The portal dashboard renders blocks using these coordinates instead of the current hardcoded layout. A new UserParameter model stores per-user key-value data exposed to blocks via content templating.

**Tech Stack:** Laravel 12 / PHP 8.5, Vue 3 + Inertia.js, CSS Grid, custom pointer-event drag-and-drop (no external library), PHPUnit, Vitest

**Spec:** `docs/superpowers/specs/2026-04-21-dashboard-editor-design.md`

---

## File Structure

### New Files
- `database/migrations/YYYY_MM_DD_HHMMSS_add_grid_columns_to_content_blocks_table.php` — add grid_col, grid_row, col_span, row_span; drop sort_order; rename pihole_toggle→dns_filter
- `database/migrations/YYYY_MM_DD_HHMMSS_create_user_parameters_table.php` — new UserParameter table
- `app/Models/UserParameter.php` — per-user key-value store
- `database/factories/UserParameterFactory.php` — factory for tests
- `resources/js/Components/Blocks/ConnectionStripBlock.vue` — replaces hardcoded connection strip
- `resources/js/Components/Blocks/DnsFilterBlock.vue` — replaces PiHoleToggleBlock
- `resources/js/Pages/Admin/Content/Editor.vue` — grid editor page
- `resources/js/Components/Admin/Content/EditorSidePanel.vue` — slide-in block editing panel
- `resources/js/Components/Admin/Content/GridCell.vue` — individual grid cell (empty or occupied)
- `resources/js/composables/useGridEditor.js` — drag/drop/resize logic composable
- `resources/js/utils/contentTemplating.js` — `{user.seat}`, `{ip}`, `{mac}` replacement
- `tests/Unit/Models/UserParameterTest.php`
- `tests/Feature/Admin/ContentControllerGridTest.php` — tests for new grid endpoints
- `tests/js/Components/Blocks/ConnectionStripBlock.spec.js`
- `tests/js/Components/Blocks/DnsFilterBlock.spec.js`
- `tests/js/Pages/Admin/Content/Editor.spec.js`
- `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`
- `tests/js/composables/useGridEditor.spec.js`
- `tests/js/utils/contentTemplating.spec.js`

### Renamed Files
- `app/Http/Controllers/Portal/PiHoleController.php` → `app/Http/Controllers/Portal/DnsFilterController.php` — generic dns-filter toggle

### Modified Files
- `app/Models/ContentBlock.php` — active scope ordering, SINGLETON_TYPES constant
- `app/Http/Controllers/Admin/ContentController.php` — singleton enforcement, updateLayout, editor route, remove reorder
- `app/Http/Controllers/Portal/DashboardController.php` — build blockContext with MAC + user params
- `database/factories/ContentBlockFactory.php` — add grid column defaults, new states
- `database/seeders/ContentBlockSeeder.php` — new types, grid coordinates
- `resources/js/Components/BlockGrid.vue` — CSS Grid renderer, updated component registry
- `resources/js/Pages/Portal/Dashboard.vue` — remove hardcoded layout, pass blockContext
- `resources/js/Pages/Admin/Content/Index.vue` — add/delete/toggle UI, link to editor
- `routes/web.php` — new routes, remove reorder, rename pihole route to dns-filter
- `tests/Feature/Admin/ContentControllerTest.php` — update reorder test, add grid tests
- `tests/Unit/ContentBlockModelTest.php` — update sort_order tests to grid ordering
- `tests/Feature/Portal/DashboardControllerTest.php` — blockContext tests
- `tests/js/Components/BlockGrid.spec.js` — update for CSS Grid rendering
- `tests/js/Pages/Portal/Dashboard.spec.js` — update for new layout

### Deleted Files
- `resources/js/Components/Blocks/PiHoleToggleBlock.vue` — replaced by DnsFilterBlock.vue
- `app/Http/Controllers/Portal/PiHoleController.php` — replaced by DnsFilterController.php

---

### Task 1: Migration — Grid Columns and Block Type Rename

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_grid_columns_to_content_blocks_table.php`
- Test: `tests/Feature/Migrations/GridColumnsMigrationTest.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_grid_columns_to_content_blocks_table --no-interaction
```

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_blocks', function (Blueprint $table) {
            $table->integer('grid_col')->default(1)->after('is_active');
            $table->integer('grid_row')->default(1)->after('grid_col');
            $table->integer('col_span')->default(1)->after('grid_row');
            $table->integer('row_span')->default(1)->after('col_span');
        });

        // Assign default grid positions to existing blocks based on sort_order
        $blocks = DB::table('content_blocks')->orderBy('sort_order')->get();
        $col = 1;
        $row = 1;
        foreach ($blocks as $block) {
            DB::table('content_blocks')->where('id', $block->id)->update([
                'grid_col' => $col,
                'grid_row' => $row,
            ]);
            $col++;
            if ($col > 3) {
                $col = 1;
                $row++;
            }
        }

        // Rename pihole_toggle to dns_filter
        DB::table('content_blocks')
            ->where('type', 'pihole_toggle')
            ->update(['type' => 'dns_filter']);

        Schema::table('content_blocks', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('content_blocks', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('is_active');
        });

        // Restore sort_order from grid positions
        $blocks = DB::table('content_blocks')
            ->orderBy('grid_row')
            ->orderBy('grid_col')
            ->get();
        $order = 10;
        foreach ($blocks as $block) {
            DB::table('content_blocks')->where('id', $block->id)->update([
                'sort_order' => $order,
            ]);
            $order += 10;
        }

        DB::table('content_blocks')
            ->where('type', 'dns_filter')
            ->update(['type' => 'pihole_toggle']);

        Schema::table('content_blocks', function (Blueprint $table) {
            $table->dropColumn(['grid_col', 'grid_row', 'col_span', 'row_span']);
        });
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*add_grid_columns*
git commit -m "feat: add grid columns to content_blocks, rename pihole_toggle to dns_filter"
```

---

### Task 2: ContentBlock Model and Factory Updates

**Files:**
- Modify: `app/Models/ContentBlock.php`
- Modify: `database/factories/ContentBlockFactory.php`
- Modify: `tests/Unit/ContentBlockModelTest.php`

- [ ] **Step 1: Update the failing test for active scope ordering**

In `tests/Unit/ContentBlockModelTest.php`, replace `test_active_scope_orders_by_sort_order`:

```php
public function test_active_scope_orders_by_grid_row_then_grid_col(): void
{
    ContentBlock::factory()->create(['grid_row' => 2, 'grid_col' => 1, 'title' => 'Row2Col1', 'is_active' => true]);
    ContentBlock::factory()->create(['grid_row' => 1, 'grid_col' => 3, 'title' => 'Row1Col3', 'is_active' => true]);
    ContentBlock::factory()->create(['grid_row' => 1, 'grid_col' => 1, 'title' => 'Row1Col1', 'is_active' => true]);

    $blocks = ContentBlock::active()->get();

    $this->assertEquals(['Row1Col1', 'Row1Col3', 'Row2Col1'], $blocks->pluck('title')->toArray());
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=test_active_scope_orders_by_grid_row_then_grid_col
```

Expected: FAIL (still ordering by sort_order, column doesn't exist after migration)

- [ ] **Step 3: Update ContentBlock model**

In `app/Models/ContentBlock.php`:

Replace the `$fillable` array:
```php
protected $fillable = [
    'type',
    'title',
    'content',
    'grid_col',
    'grid_row',
    'col_span',
    'row_span',
    'is_active',
    'settings',
];
```

Replace the `active` scope:
```php
#[Scope]
protected function active(Builder $query): Builder
{
    return $query->where('is_active', true)
        ->orderBy('grid_row')
        ->orderBy('grid_col');
}
```

Add singleton types constant at the top of the class:
```php
/** @var list<string> */
public const SINGLETON_TYPES = [
    'connection_strip',
    'bandwidth',
    'network_stats',
    'dns_filter',
    'connection_status',
];
```

- [ ] **Step 4: Update factory**

In `database/factories/ContentBlockFactory.php`, replace the `definition` method:

```php
public function definition(): array
{
    return [
        'type' => fake()->randomElement(['event_info', 'connection_status', 'bandwidth', 'network_stats', 'custom_markdown']),
        'title' => fake()->sentence(3),
        'content' => fake()->optional()->paragraph(),
        'grid_col' => 1,
        'grid_row' => 1,
        'col_span' => 1,
        'row_span' => 1,
        'is_active' => true,
        'settings' => null,
    ];
}
```

Add new states:

```php
public function connectionStrip(): static
{
    return $this->state(fn (array $attributes) => [
        'type' => 'connection_strip',
        'title' => 'Connection Status',
    ]);
}

public function dnsFilter(): static
{
    return $this->state(fn (array $attributes) => [
        'type' => 'dns_filter',
        'title' => 'DNS Ad Blocking',
        'content' => 'Toggle DNS filtering for your connection.',
    ]);
}

public function bandwidth(): static
{
    return $this->state(fn (array $attributes) => [
        'type' => 'bandwidth',
        'title' => 'Bandwidth',
    ]);
}

public function networkStats(): static
{
    return $this->state(fn (array $attributes) => [
        'type' => 'network_stats',
        'title' => 'Network Stats',
    ]);
}

public function atPosition(int $col, int $row, int $colSpan = 1, int $rowSpan = 1): static
{
    return $this->state(fn (array $attributes) => [
        'grid_col' => $col,
        'grid_row' => $row,
        'col_span' => $colSpan,
        'row_span' => $rowSpan,
    ]);
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter=ContentBlockModelTest
```

Expected: All pass

- [ ] **Step 6: Commit**

```bash
git add app/Models/ContentBlock.php database/factories/ContentBlockFactory.php tests/Unit/ContentBlockModelTest.php
git commit -m "feat: update ContentBlock model with grid coordinates and singleton types"
```

---

### Task 3: UserParameter Model

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_user_parameters_table.php`
- Create: `app/Models/UserParameter.php`
- Create: `database/factories/UserParameterFactory.php`
- Modify: `app/Models/User.php` (add relationship)
- Create: `tests/Unit/Models/UserParameterTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Models/UserParameterTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserParameterTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_user_parameter(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'seat',
            'value' => 'A42',
        ]);

        $this->assertDatabaseHas('user_parameters', [
            'user_id' => $user->id,
            'key' => 'seat',
        ]);
        $this->assertEquals('A42', $param->value);
    }

    public function test_unique_constraint_on_user_and_key(): void
    {
        $user = User::factory()->create();
        UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'seat',
            'value' => 'A42',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'seat',
            'value' => 'B17',
        ]);
    }

    public function test_value_casts_to_object(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'preferences',
            'value' => ['theme' => 'dark', 'lang' => 'en'],
        ]);

        $param->refresh();
        $this->assertEquals('dark', $param->value['theme']);
    }

    public function test_user_has_parameters_relationship(): void
    {
        $user = User::factory()->create();
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat', 'value' => 'A42']);
        UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'team', 'value' => 'Red']);

        $this->assertCount(2, $user->parameters);
        $this->assertEquals(['seat' => 'A42', 'team' => 'Red'], $user->parameters->pluck('value', 'key')->toArray());
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $param = UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat']);

        $this->assertTrue($param->user->is($user));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=UserParameterTest
```

Expected: FAIL (class and table don't exist)

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_user_parameters_table --no-interaction
```

Write the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_parameters');
    }
};
```

- [ ] **Step 4: Create the model**

```bash
php artisan make:class app/Models/UserParameter --no-interaction
```

Write `app/Models/UserParameter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserParameterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserParameter extends Model
{
    /** @use HasFactory<UserParameterFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Create the factory**

```bash
php artisan make:factory UserParameterFactory --no-interaction
```

Write `database/factories/UserParameterFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserParameter>
 */
class UserParameterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => fake()->unique()->word(),
            'value' => fake()->word(),
        ];
    }
}
```

- [ ] **Step 6: Add `parameters` relationship to User model**

In `app/Models/User.php`, add the import and relationship method:

```php
use App\Models\UserParameter;
```

```php
/** @return HasMany<UserParameter, $this> */
public function parameters(): HasMany
{
    return $this->hasMany(UserParameter::class);
}
```

Ensure `HasMany` is imported (it likely already is from existing relationships).

- [ ] **Step 7: Run migration and tests**

```bash
php artisan migrate
php artisan test --compact --filter=UserParameterTest
```

Expected: All 5 tests pass

- [ ] **Step 8: Commit**

```bash
git add app/Models/UserParameter.php app/Models/User.php database/migrations/*create_user_parameters* database/factories/UserParameterFactory.php tests/Unit/Models/UserParameterTest.php
git commit -m "feat: add UserParameter model for per-user key-value data"
```

---

### Task 4: ContentController — Singleton Enforcement, Layout Update, Editor Route

**Files:**
- Modify: `app/Http/Controllers/Admin/ContentController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Admin/ContentControllerTest.php`
- Create: `tests/Feature/Admin/ContentControllerGridTest.php`

- [ ] **Step 1: Write failing tests for singleton enforcement**

Create `tests/Feature/Admin/ContentControllerGridTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\ContentBlock;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentControllerGridTest extends TestCase
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

    public function test_cannot_create_duplicate_singleton_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->create(['type' => 'bandwidth']);

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'bandwidth',
            'title' => 'Another Bandwidth',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_can_create_duplicate_non_singleton_block(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->create(['type' => 'custom_markdown', 'title' => 'First']);

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'custom_markdown',
            'title' => 'Second',
        ]);

        $response->assertCreated();
        $this->assertEquals(2, ContentBlock::where('type', 'custom_markdown')->count());
    }

    public function test_update_layout_saves_grid_positions(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block1 = ContentBlock::factory()->create(['grid_col' => 1, 'grid_row' => 1]);
        $block2 = ContentBlock::factory()->create(['grid_col' => 2, 'grid_row' => 1]);

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block1->id, 'grid_col' => 1, 'grid_row' => 2, 'col_span' => 2, 'row_span' => 1],
                ['id' => $block2->id, 'grid_col' => 3, 'grid_row' => 1, 'col_span' => 1, 'row_span' => 1],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('content_blocks', ['id' => $block1->id, 'grid_col' => 1, 'grid_row' => 2, 'col_span' => 2]);
        $this->assertDatabaseHas('content_blocks', ['id' => $block2->id, 'grid_col' => 3, 'grid_row' => 1]);
    }

    public function test_update_layout_validates_column_bounds(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block->id, 'grid_col' => 4, 'grid_row' => 1, 'col_span' => 1, 'row_span' => 1],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_layout_validates_span_fits_grid(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block->id, 'grid_col' => 2, 'grid_row' => 1, 'col_span' => 3, 'row_span' => 1],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_layout_rejects_overlapping_blocks(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $block1 = ContentBlock::factory()->create();
        $block2 = ContentBlock::factory()->create();

        $response = $this->actingAs($admin)->putJson('/admin/content/layout', [
            'blocks' => [
                ['id' => $block1->id, 'grid_col' => 1, 'grid_row' => 1, 'col_span' => 2, 'row_span' => 1],
                ['id' => $block2->id, 'grid_col' => 2, 'grid_row' => 1, 'col_span' => 1, 'row_span' => 1],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_editor_page_loads(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/admin/content/editor');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Content/Editor')
            ->has('blocks', 3)
        );
    }

    public function test_store_assigns_first_available_grid_position(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        ContentBlock::factory()->create(['grid_col' => 1, 'grid_row' => 1]);
        ContentBlock::factory()->create(['grid_col' => 2, 'grid_row' => 1]);

        $response = $this->actingAs($admin)->postJson('/admin/content', [
            'type' => 'custom_markdown',
            'title' => 'New Block',
        ]);

        $response->assertCreated();
        $block = ContentBlock::where('title', 'New Block')->first();
        $this->assertEquals(3, $block->grid_col);
        $this->assertEquals(1, $block->grid_row);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=ContentControllerGridTest
```

Expected: FAIL

- [ ] **Step 3: Update ContentController**

Replace `app/Http/Controllers/Admin/ContentController.php` entirely:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends Controller
{
    public function index(): Response
    {
        $blocks = ContentBlock::orderBy('grid_row')->orderBy('grid_col')->get();

        return Inertia::render('Admin/Content/Index', [
            'blocks' => $blocks,
            'singletonTypes' => ContentBlock::SINGLETON_TYPES,
            'existingTypes' => ContentBlock::pluck('type')->unique()->values(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content'],
            ],
        ]);
    }

    public function editor(): Response
    {
        $blocks = ContentBlock::orderBy('grid_row')->orderBy('grid_col')->get();

        return Inertia::render('Admin/Content/Editor', [
            'blocks' => $blocks,
            'singletonTypes' => ContentBlock::SINGLETON_TYPES,
            'existingTypes' => ContentBlock::pluck('type')->unique()->values(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Content', 'href' => route('admin.content.index')],
                ['label' => 'Grid Editor'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (
                        in_array($value, ContentBlock::SINGLETON_TYPES, true)
                        && ContentBlock::where('type', $value)->exists()
                    ) {
                        $fail('A block of this type already exists.');
                    }
                },
            ],
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        // Find first available grid position
        $position = $this->findFirstAvailablePosition();
        $validated['grid_col'] = $position['col'];
        $validated['grid_row'] = $position['row'];

        $block = ContentBlock::create($validated);

        return response()->json($block, 201);
    }

    public function update(Request $request, ContentBlock $content): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'sometimes|string|max:50',
            'title' => 'sometimes|string|max:255',
            'content' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
        ]);

        $content->update($validated);

        return response()->json($content);
    }

    public function destroy(ContentBlock $content): JsonResponse
    {
        $content->delete();

        return response()->json(null, 204);
    }

    public function updateLayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'blocks' => 'required|array',
            'blocks.*.id' => 'required|exists:content_blocks,id',
            'blocks.*.grid_col' => 'required|integer|min:1|max:3',
            'blocks.*.grid_row' => 'required|integer|min:1',
            'blocks.*.col_span' => 'required|integer|min:1|max:3',
            'blocks.*.row_span' => 'required|integer|min:1',
        ]);

        // Validate spans don't exceed grid bounds
        foreach ($validated['blocks'] as $blockData) {
            if ($blockData['grid_col'] + $blockData['col_span'] - 1 > 3) {
                return response()->json([
                    'message' => 'Block exceeds grid width.',
                    'errors' => ['blocks' => ['A block exceeds the 3-column grid width.']],
                ], 422);
            }
        }

        // Validate no overlaps
        if ($this->hasOverlaps($validated['blocks'])) {
            return response()->json([
                'message' => 'Blocks overlap.',
                'errors' => ['blocks' => ['Two or more blocks overlap in the grid.']],
            ], 422);
        }

        DB::transaction(function () use ($validated): void {
            foreach ($validated['blocks'] as $blockData) {
                ContentBlock::where('id', $blockData['id'])->update([
                    'grid_col' => $blockData['grid_col'],
                    'grid_row' => $blockData['grid_row'],
                    'col_span' => $blockData['col_span'],
                    'row_span' => $blockData['row_span'],
                ]);
            }
        });

        return response()->json(['message' => 'Layout updated.']);
    }

    /**
     * @param array<int, array{grid_col: int, grid_row: int, col_span: int, row_span: int}> $blocks
     */
    private function hasOverlaps(array $blocks): bool
    {
        $occupied = [];

        foreach ($blocks as $block) {
            for ($c = $block['grid_col']; $c < $block['grid_col'] + $block['col_span']; $c++) {
                for ($r = $block['grid_row']; $r < $block['grid_row'] + $block['row_span']; $r++) {
                    $key = "{$c},{$r}";
                    if (isset($occupied[$key])) {
                        return true;
                    }
                    $occupied[$key] = true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{col: int, row: int}
     */
    private function findFirstAvailablePosition(): array
    {
        $blocks = ContentBlock::all();
        $occupied = [];

        foreach ($blocks as $block) {
            for ($c = $block->grid_col; $c < $block->grid_col + $block->col_span; $c++) {
                for ($r = $block->grid_row; $r < $block->grid_row + $block->row_span; $r++) {
                    $occupied["{$c},{$r}"] = true;
                }
            }
        }

        // Scan row by row, col by col
        for ($row = 1; $row <= 100; $row++) {
            for ($col = 1; $col <= 3; $col++) {
                if (! isset($occupied["{$col},{$row}"])) {
                    return ['col' => $col, 'row' => $row];
                }
            }
        }

        return ['col' => 1, 'row' => 1];
    }
}
```

- [ ] **Step 4: Update routes**

In `routes/web.php`, inside the admin group:

Replace:
```php
        // Content blocks
        Route::resource('content', ContentController::class)->except(['create', 'edit']);
        Route::post('/content/reorder', [ContentController::class, 'reorder'])->name('content.reorder');
```

With:
```php
        // Content blocks
        Route::resource('content', ContentController::class)->except(['create', 'edit']);
        Route::get('/content/editor', [ContentController::class, 'editor'])->name('content.editor');
        Route::put('/content/layout', [ContentController::class, 'updateLayout'])->name('content.layout.update');
```

**Important:** The `editor` GET route must be registered **before** the resource routes, otherwise `/content/editor` will be caught by `content/{content}` show route. Reorder to:

```php
        // Content blocks
        Route::get('/content/editor', [ContentController::class, 'editor'])->name('content.editor');
        Route::put('/content/layout', [ContentController::class, 'updateLayout'])->name('content.layout.update');
        Route::resource('content', ContentController::class)->except(['create', 'edit', 'show']);
```

- [ ] **Step 5: Update existing ContentControllerTest**

In `tests/Feature/Admin/ContentControllerTest.php`:

Replace `test_admin_can_reorder_content_blocks` with:

```php
public function test_admin_can_view_content_blocks(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();
    ContentBlock::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get('/admin/content');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Content/Index')
        ->has('blocks', 3)
        ->has('singletonTypes')
        ->has('existingTypes')
    );
}
```

Remove the `test_admin_can_reorder_content_blocks` test entirely.

- [ ] **Step 6: Create stub Editor.vue** (so the Inertia render doesn't fail)

Create `resources/js/Pages/Admin/Content/Editor.vue`:

```vue
<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    blocks: { type: Array, default: () => [] },
    singletonTypes: { type: Array, default: () => [] },
    existingTypes: { type: Array, default: () => [] },
});
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
        >
            Grid Editor
        </h1>
    </div>
</template>
```

- [ ] **Step 7: Run all tests**

```bash
php artisan test --compact --filter=ContentController
```

Expected: All pass

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/ContentController.php routes/web.php tests/Feature/Admin/ContentControllerTest.php tests/Feature/Admin/ContentControllerGridTest.php resources/js/Pages/Admin/Content/Editor.vue
git commit -m "feat: add singleton enforcement, layout update endpoint, and grid editor route"
```

---

### Task 5: ConnectionStripBlock Component

**Files:**
- Create: `resources/js/Components/Blocks/ConnectionStripBlock.vue`
- Create: `tests/js/Components/Blocks/ConnectionStripBlock.spec.js`

- [ ] **Step 1: Write failing tests**

Create `tests/js/Components/Blocks/ConnectionStripBlock.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectionStripBlock from '@/Components/Blocks/ConnectionStripBlock.vue';

describe('ConnectionStripBlock', () => {
    const defaultContext = {
        currentIp: '192.168.1.42',
        ipAllowed: true,
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: {},
    };

    it('renders IPv4 address from block context', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('192.168.1.42');
    });

    it('renders MAC address from block context', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('AA:BB:CC:DD:EE:FF');
    });

    it('shows Online status when ipAllowed is true', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('Online');
    });

    it('shows Offline status when ipAllowed is false', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: { ...defaultContext, ipAllowed: false } },
        });
        expect(wrapper.text()).toContain('Offline');
    });

    it('shows dash when MAC address is null', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: { ...defaultContext, macAddress: null } },
        });
        const macSection = wrapper.find('[data-testid="connection-strip-mac"]');
        expect(macSection.text()).toContain('—');
    });

    it('shows dash when IP is empty', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: { ...defaultContext, currentIp: '' } },
        });
        expect(wrapper.text()).toContain('—');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
npx vitest run tests/js/Components/Blocks/ConnectionStripBlock.spec.js
```

Expected: FAIL (component doesn't exist)

- [ ] **Step 3: Create the component**

Create `resources/js/Components/Blocks/ConnectionStripBlock.vue`:

```vue
<script setup>
defineProps({
    title: { type: String, default: 'Connection Status' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div data-testid="block-connection-strip">
        <div class="flex items-center">
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] pr-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >IPv4</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">{{
                    blockContext.currentIp || '—'
                }}</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >IPv6</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">—</span>
            </div>
            <div
                data-testid="connection-strip-mac"
                class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5"
            >
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >MAC Address</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">{{
                    blockContext.macAddress || '—'
                }}</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 pl-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >Status</span
                >
                <span class="inline-flex items-center gap-1.5 font-mono text-[13px] font-semibold">
                    <span
                        class="h-[7px] w-[7px] rounded-full"
                        :class="
                            blockContext.ipAllowed
                                ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                : 'bg-[var(--color-danger)]'
                        "
                    />
                    <span
                        :class="
                            blockContext.ipAllowed ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'
                        "
                    >
                        {{ blockContext.ipAllowed ? 'Online' : 'Offline' }}
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run tests**

```bash
npx vitest run tests/js/Components/Blocks/ConnectionStripBlock.spec.js
```

Expected: All 6 pass

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Blocks/ConnectionStripBlock.vue tests/js/Components/Blocks/ConnectionStripBlock.spec.js
git commit -m "feat: add ConnectionStripBlock component"
```

---

### Task 6: DnsFilterBlock Component (Rename from PiHoleToggleBlock)

**Files:**
- Create: `resources/js/Components/Blocks/DnsFilterBlock.vue`
- Delete: `resources/js/Components/Blocks/PiHoleToggleBlock.vue`
- Modify: `tests/js/Components/Blocks/PiHoleToggleBlock.spec.js` → rename to `DnsFilterBlock.spec.js`

- [ ] **Step 1: Write failing tests**

Create `tests/js/Components/Blocks/DnsFilterBlock.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import DnsFilterBlock from '@/Components/Blocks/DnsFilterBlock.vue';

vi.stubGlobal('route', vi.fn(() => '/mock/dns-filter/toggle'));

describe('DnsFilterBlock', () => {
    beforeEach(() => {
        global.fetch = vi.fn();
        document.querySelector = vi.fn(() => ({ getAttribute: () => 'test-token' }));
    });

    it('renders the title from props', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { title: 'My DNS Filter' },
        });
        expect(wrapper.text()).toContain('My DNS Filter');
    });

    it('renders default title when not provided', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.text()).toContain('DNS Ad Blocking');
    });

    it('renders the content/description from props', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { content: 'Custom description here' },
        });
        expect(wrapper.text()).toContain('Custom description here');
    });

    it('has a toggle button', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.find('[data-testid="dns-filter-toggle"]').exists()).toBe(true);
    });

    it('calls toggle endpoint on click', async () => {
        global.fetch.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ enabled: true }),
        });

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('dns-filter/toggle'),
            expect.objectContaining({ method: 'POST' }),
        );
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
npx vitest run tests/js/Components/Blocks/DnsFilterBlock.spec.js
```

Expected: FAIL

- [ ] **Step 3: Create DnsFilterBlock.vue**

Create `resources/js/Components/Blocks/DnsFilterBlock.vue`:

```vue
<script setup>
import { ref } from 'vue';

const props = defineProps({
    title: { type: String, default: 'DNS Ad Blocking' },
    content: { type: String, default: 'Toggle DNS filtering for your connection.' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const enabled = ref(false);
const loading = ref(false);

async function toggle() {
    loading.value = true;
    try {
        const response = await fetch(route('portal.dns-filter.toggle'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
        });
        if (response.ok) {
            const data = await response.json();
            enabled.value = data.enabled;
        }
    } catch (_e) {
        // Silently fail
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div data-testid="block-dns-filter">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ title }}
        </h3>
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold text-[var(--color-text)]">DNS Filtering</div>
                <div class="mt-0.5 text-xs text-[var(--color-text-muted)]">{{ content }}</div>
            </div>
            <button
                :disabled="loading"
                data-testid="dns-filter-toggle"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none"
                :class="enabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-surface-alt)]'"
                @click="toggle"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="enabled ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Rename PiHoleController to DnsFilterController**

Rename `app/Http/Controllers/Portal/PiHoleController.php` to `app/Http/Controllers/Portal/DnsFilterController.php`. Update the class name and namespace accordingly. The `toggle` method stays the same — it already uses `DnsBlockingInterface`, so only the class/file name changes.

```bash
mv app/Http/Controllers/Portal/PiHoleController.php app/Http/Controllers/Portal/DnsFilterController.php
```

In `app/Http/Controllers/Portal/DnsFilterController.php`, change:
```php
class PiHoleController extends Controller
```
to:
```php
class DnsFilterController extends Controller
```

- [ ] **Step 5: Update portal route**

In `routes/web.php`, replace:
```php
use App\Http\Controllers\Portal\PiHoleController;
```
with:
```php
use App\Http\Controllers\Portal\DnsFilterController;
```

Replace:
```php
Route::post('/pihole/toggle', [PiHoleController::class, 'toggle'])->name('portal.pihole.toggle');
```
with:
```php
Route::post('/dns-filter/toggle', [DnsFilterController::class, 'toggle'])->name('portal.dns-filter.toggle');
```

- [ ] **Step 6: Update PiHoleController tests**

In `tests/Feature/Portal/PiHoleControllerTest.php` (or equivalent), update the route references from `portal.pihole.toggle` to `portal.dns-filter.toggle` and rename the test file to `tests/Feature/Portal/DnsFilterControllerTest.php`.

- [ ] **Step 7: Delete PiHoleToggleBlock.vue**

```bash
rm resources/js/Components/Blocks/PiHoleToggleBlock.vue
```

- [ ] **Step 8: Delete old test and rename**

```bash
rm tests/js/Components/Blocks/PiHoleToggleBlock.spec.js
```

(The new test file was already created in Step 1)

- [ ] **Step 9: Run tests**

```bash
npx vitest run tests/js/Components/Blocks/DnsFilterBlock.spec.js
php artisan test --compact --filter=DnsFilter
```

Expected: All pass

- [ ] **Step 10: Commit**

```bash
git add resources/js/Components/Blocks/DnsFilterBlock.vue tests/js/Components/Blocks/DnsFilterBlock.spec.js app/Http/Controllers/Portal/DnsFilterController.php routes/web.php
git rm resources/js/Components/Blocks/PiHoleToggleBlock.vue tests/js/Components/Blocks/PiHoleToggleBlock.spec.js app/Http/Controllers/Portal/PiHoleController.php
git commit -m "feat: replace PiHoleToggle with DnsFilter — generic controller, route, and component"
```

---

### Task 7: Content Templating Utility

**Files:**
- Create: `resources/js/utils/contentTemplating.js`
- Create: `tests/js/utils/contentTemplating.spec.js`

- [ ] **Step 1: Write failing tests**

Create `tests/js/utils/contentTemplating.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { renderTemplate } from '@/utils/contentTemplating.js';

describe('renderTemplate', () => {
    const context = {
        currentIp: '192.168.1.42',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: { seat: 'A42', team: 'Red' },
    };

    it('replaces {user.seat} with user parameter value', () => {
        expect(renderTemplate('Your seat is {user.seat}', context)).toBe('Your seat is A42');
    });

    it('replaces {user.team} with user parameter value', () => {
        expect(renderTemplate('Team: {user.team}', context)).toBe('Team: Red');
    });

    it('replaces {ip} with current IP', () => {
        expect(renderTemplate('IP: {ip}', context)).toBe('IP: 192.168.1.42');
    });

    it('replaces {mac} with MAC address', () => {
        expect(renderTemplate('MAC: {mac}', context)).toBe('MAC: AA:BB:CC:DD:EE:FF');
    });

    it('replaces multiple placeholders in one string', () => {
        expect(renderTemplate('Seat {user.seat} at {ip}', context)).toBe('Seat A42 at 192.168.1.42');
    });

    it('renders empty string for missing user parameter', () => {
        expect(renderTemplate('Value: {user.missing}', context)).toBe('Value: ');
    });

    it('renders empty string for null MAC', () => {
        const ctx = { ...context, macAddress: null };
        expect(renderTemplate('MAC: {mac}', ctx)).toBe('MAC: ');
    });

    it('returns original string when no placeholders', () => {
        expect(renderTemplate('No placeholders here', context)).toBe('No placeholders here');
    });

    it('handles null content gracefully', () => {
        expect(renderTemplate(null, context)).toBe('');
    });

    it('handles empty string content', () => {
        expect(renderTemplate('', context)).toBe('');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
npx vitest run tests/js/utils/contentTemplating.spec.js
```

Expected: FAIL

- [ ] **Step 3: Implement the utility**

Create `resources/js/utils/contentTemplating.js`:

```js
/**
 * Replace template placeholders in content with values from block context.
 *
 * Supported placeholders:
 *   {user.<key>}  - User parameter value
 *   {ip}          - Current IP address
 *   {mac}         - MAC address
 *
 * @param {string|null} content - Template string with placeholders
 * @param {Object} context - Block context with currentIp, macAddress, user
 * @returns {string} Rendered content
 */
export function renderTemplate(content, context) {
    if (!content) return '';

    return content
        .replace(/\{user\.([^}]+)\}/g, (_, key) => context.user?.[key] ?? '')
        .replace(/\{ip\}/g, context.currentIp ?? '')
        .replace(/\{mac\}/g, context.macAddress ?? '');
}
```

- [ ] **Step 4: Run tests**

```bash
npx vitest run tests/js/utils/contentTemplating.spec.js
```

Expected: All 10 pass

- [ ] **Step 5: Commit**

```bash
git add resources/js/utils/contentTemplating.js tests/js/utils/contentTemplating.spec.js
git commit -m "feat: add content templating utility for {user.*}, {ip}, {mac} placeholders"
```

---

### Task 8: BlockGrid Rewrite — CSS Grid Renderer

**Files:**
- Modify: `resources/js/Components/BlockGrid.vue`
- Modify: `tests/js/Components/BlockGrid.spec.js`

- [ ] **Step 1: Write failing tests**

Replace `tests/js/Components/BlockGrid.spec.js` entirely:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BlockGrid from '@/Components/BlockGrid.vue';

// Mock route function globally
vi.stubGlobal('route', vi.fn(() => '/mock-route'));

describe('BlockGrid', () => {
    const defaultContext = {
        currentIp: '192.168.1.42',
        ipAllowed: true,
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: {},
    };

    it('renders a CSS grid container', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-grid"]').exists()).toBe(true);
    });

    it('positions blocks using grid-column and grid-row styles', () => {
        const blocks = [
            { id: 1, type: 'event_info', title: 'Info', content: 'Hello', grid_col: 2, grid_row: 3, col_span: 2, row_span: 1, is_active: true, settings: null },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        const blockEl = wrapper.find('[data-testid="block-event_info-wrapper"]');
        expect(blockEl.attributes('style')).toContain('grid-column: 2 / span 2');
        expect(blockEl.attributes('style')).toContain('grid-row: 3 / span 1');
    });

    it('does not render blocks with unknown type', () => {
        const blocks = [
            { id: 1, type: 'nonexistent', title: 'X', content: '', grid_col: 1, grid_row: 1, col_span: 1, row_span: 1, is_active: true, settings: null },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.findAll('[data-testid]').filter(w => w.attributes('data-testid')?.includes('wrapper')).length).toBe(0);
    });

    it('renders multiple blocks at their positions', () => {
        const blocks = [
            { id: 1, type: 'event_info', title: 'A', content: 'Hello', grid_col: 1, grid_row: 1, col_span: 1, row_span: 1, is_active: true, settings: null },
            { id: 2, type: 'custom_markdown', title: 'B', content: 'World', grid_col: 2, grid_row: 1, col_span: 1, row_span: 1, is_active: true, settings: null },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.find('[data-testid="block-event_info-wrapper"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="block-custom_markdown-wrapper"]').exists()).toBe(true);
    });

    it('passes blockContext to block components', () => {
        const blocks = [
            { id: 1, type: 'connection_strip', title: 'Connection', content: '', grid_col: 1, grid_row: 1, col_span: 3, row_span: 1, is_active: true, settings: null },
        ];
        const wrapper = mount(BlockGrid, {
            props: { blocks, blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('192.168.1.42');
    });

    it('renders empty state when no blocks', () => {
        const wrapper = mount(BlockGrid, {
            props: { blocks: [], blockContext: defaultContext },
        });
        const grid = wrapper.find('[data-testid="block-grid"]');
        expect(grid.exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
npx vitest run tests/js/Components/BlockGrid.spec.js
```

Expected: FAIL (old component doesn't have blockContext or CSS Grid positioning)

- [ ] **Step 3: Rewrite BlockGrid.vue**

Replace `resources/js/Components/BlockGrid.vue`:

```vue
<script setup>
import EventInfoBlock from './Blocks/EventInfoBlock.vue';
import ConnectionStatusBlock from './Blocks/ConnectionStatusBlock.vue';
import ConnectionStripBlock from './Blocks/ConnectionStripBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import NetworkStatsBlock from './Blocks/NetworkStatsBlock.vue';
import DnsFilterBlock from './Blocks/DnsFilterBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const blockComponents = {
    event_info: EventInfoBlock,
    connection_status: ConnectionStatusBlock,
    connection_strip: ConnectionStripBlock,
    bandwidth: BandwidthBlock,
    network_stats: NetworkStatsBlock,
    dns_filter: DnsFilterBlock,
    custom_markdown: CustomMarkdownBlock,
};

const props = defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    blockContext: {
        type: Object,
        default: () => ({}),
    },
});

function blockStyle(block) {
    return {
        gridColumn: `${block.grid_col} / span ${block.col_span}`,
        gridRow: `${block.grid_row} / span ${block.row_span}`,
    };
}

function templateContent(block) {
    if (['event_info', 'custom_markdown'].includes(block.type)) {
        return renderTemplate(block.content, props.blockContext);
    }
    return block.content;
}
</script>

<template>
    <div
        data-testid="block-grid"
        class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3"
    >
        <template v-for="block in blocks" :key="block.id">
            <div
                v-if="blockComponents[block.type]"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-5 transition-colors hover:border-[var(--color-border-hover)]"
                :data-testid="'block-' + block.type + '-wrapper'"
                :style="blockStyle(block)"
            >
                <component
                    :is="blockComponents[block.type]"
                    :title="block.title"
                    :content="templateContent(block)"
                    :settings="block.settings"
                    :block-context="blockContext"
                />
            </div>
        </template>
    </div>
</template>
```

- [ ] **Step 4: Run tests**

```bash
npx vitest run tests/js/Components/BlockGrid.spec.js
```

Expected: All 6 pass

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/BlockGrid.vue tests/js/Components/BlockGrid.spec.js
git commit -m "feat: rewrite BlockGrid as CSS Grid renderer with block positioning and templating"
```

---

### Task 9: DashboardController + Dashboard.vue — blockContext and Layout Rewrite

**Files:**
- Modify: `app/Http/Controllers/Portal/DashboardController.php`
- Modify: `resources/js/Pages/Portal/Dashboard.vue`
- Modify: `tests/Feature/Portal/DashboardControllerTest.php`
- Modify: `tests/js/Pages/Portal/Dashboard.spec.js`

- [ ] **Step 1: Write failing PHP test for blockContext**

In `tests/Feature/Portal/DashboardControllerTest.php`, add:

```php
public function test_dashboard_passes_block_context_with_mac_and_user_params(): void
{
    Queue::fake();
    $user = User::factory()->create();
    $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);
    UserIpAddress::factory()->create([
        'user_id' => $user->id,
        'ip_address_id' => $ip->id,
    ]);
    $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    $ip->macAddress()->associate($mac);
    $ip->save();

    UserParameter::factory()->create(['user_id' => $user->id, 'key' => 'seat', 'value' => 'A42']);

    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->get('/portal');

    $response->assertInertia(fn ($page) => $page
        ->has('blockContext')
        ->where('blockContext.currentIp', '10.0.0.1')
        ->where('blockContext.ipAllowed', true)
        ->where('blockContext.macAddress', 'AA:BB:CC:DD:EE:FF')
        ->has('blockContext.user')
    );
}
```

Add necessary imports at top of test file:

```php
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\UserIpAddress;
use App\Models\UserParameter;
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=test_dashboard_passes_block_context_with_mac_and_user_params
```

Expected: FAIL (no blockContext prop)

- [ ] **Step 3: Update DashboardController**

Replace `app/Http/Controllers/Portal/DashboardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp((string) $request->getClientIp());

        if (! $user->blocked) {
            $ip->allow(true);
        }

        $blocks = ContentBlock::active()->get();

        $checkUrl = Setting::get('dns.check_url');
        $warningMessage = Setting::get('dns.warning_message');

        return Inertia::render('Portal/Dashboard', [
            'blocks' => $blocks,
            'blockContext' => [
                'currentIp' => $ip->address,
                'ipAllowed' => (bool) $ip->allowed,
                'macAddress' => $ip->mac,
                'user' => $user->parameters()->pluck('value', 'key'),
            ],
            'dnsDetection' => $checkUrl ? [
                'checkUrl' => $checkUrl,
                'warningMessage' => $warningMessage ?? 'Your device is not using the event DNS servers. Please update your DNS settings.',
            ] : null,
        ]);
    }
}
```

Note: `currentIp` and `ipAllowed` are now inside `blockContext` instead of as top-level props. The old `currentIp` and `ipAllowed` top-level props are removed.

- [ ] **Step 4: Update Dashboard.vue**

Replace `resources/js/Pages/Portal/Dashboard.vue`:

```vue
<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { usePage } from '@inertiajs/vue3';
import BlockGrid from '@/Components/BlockGrid.vue';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    blockContext: {
        type: Object,
        default: () => ({}),
    },
    dnsDetection: { type: Object, default: null },
});

const user = usePage().props.auth?.user;
</script>

<template>
    <div>
        <!-- Welcome heading -->
        <div class="mb-3">
            <h1
                data-testid="page-title"
                class="font-heading text-[28px] font-bold tracking-tight text-[var(--color-text)]"
            >
                Welcome, {{ user?.nickname ?? 'Guest' }}
            </h1>
        </div>

        <!-- DNS Warning (top of page, outside grid) -->
        <div v-if="dnsDetection" class="mb-4">
            <DnsWarningBlock :check-url="dnsDetection.checkUrl" :warning-message="dnsDetection.warningMessage" />
        </div>

        <!-- Block grid -->
        <BlockGrid :blocks="blocks" :block-context="blockContext" />
    </div>
</template>
```

- [ ] **Step 5: Update existing Dashboard PHP tests**

In `tests/Feature/Portal/DashboardControllerTest.php`, update any tests that reference `currentIp` or `ipAllowed` as top-level props — they are now inside `blockContext`. For example, update assertions like:

```php
->where('currentIp', '...')
```
to:
```php
->where('blockContext.currentIp', '...')
```

Also remove any references to the old `ipAllowed` top-level prop.

- [ ] **Step 6: Update Dashboard.spec.js**

Update `tests/js/Pages/Portal/Dashboard.spec.js` to pass `blockContext` instead of `currentIp`/`ipAllowed`, and to remove expectations about hero blocks or the hardcoded connection strip.

- [ ] **Step 7: Run tests**

```bash
php artisan test --compact --filter=DashboardControllerTest
npx vitest run tests/js/Pages/Portal/Dashboard.spec.js
```

Expected: All pass

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Portal/DashboardController.php resources/js/Pages/Portal/Dashboard.vue tests/Feature/Portal/DashboardControllerTest.php tests/js/Pages/Portal/Dashboard.spec.js
git commit -m "feat: rewrite dashboard with blockContext and CSS Grid-driven layout"
```

---

### Task 10: Admin Content Index — CRUD Enhancements

**Files:**
- Modify: `resources/js/Pages/Admin/Content/Index.vue`
- Modify: `tests/js/Pages/Admin/Content/Index.spec.js`

- [ ] **Step 1: Write failing tests**

Create or replace `tests/js/Pages/Admin/Content/Index.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '@/Pages/Admin/Content/Index.vue';

vi.stubGlobal('route', vi.fn((name) => `/mock/${name}`));

describe('Admin Content Index', () => {
    const defaultProps = {
        blocks: [
            { id: 1, type: 'event_info', title: 'Welcome', grid_col: 1, grid_row: 1, col_span: 1, row_span: 1, is_active: true },
            { id: 2, type: 'bandwidth', title: 'Bandwidth', grid_col: 2, grid_row: 1, col_span: 1, row_span: 1, is_active: true },
        ],
        singletonTypes: ['bandwidth', 'connection_strip', 'network_stats', 'dns_filter', 'connection_status'],
        existingTypes: ['event_info', 'bandwidth'],
    };

    it('renders the page title', () => {
        const wrapper = mount(Index, { props: defaultProps });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Content Blocks');
    });

    it('renders blocks in a table', () => {
        const wrapper = mount(Index, { props: defaultProps });
        expect(wrapper.text()).toContain('Welcome');
        expect(wrapper.text()).toContain('Bandwidth');
    });

    it('has an add block button', () => {
        const wrapper = mount(Index, { props: defaultProps });
        expect(wrapper.find('[data-testid="action-add-block"]').exists()).toBe(true);
    });

    it('has a link to the grid editor', () => {
        const wrapper = mount(Index, { props: defaultProps });
        expect(wrapper.find('[data-testid="link-grid-editor"]').exists()).toBe(true);
    });

    it('has delete buttons per block', () => {
        const wrapper = mount(Index, { props: defaultProps });
        const deleteButtons = wrapper.findAll('[data-testid^="action-delete-"]');
        expect(deleteButtons.length).toBe(2);
    });

    it('has active toggles per block', () => {
        const wrapper = mount(Index, { props: defaultProps });
        const toggles = wrapper.findAll('[data-testid^="toggle-active-"]');
        expect(toggles.length).toBe(2);
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
npx vitest run tests/js/Pages/Admin/Content/Index.spec.js
```

- [ ] **Step 3: Update Index.vue**

Replace `resources/js/Pages/Admin/Content/Index.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    singletonTypes: { type: Array, default: () => [] },
    existingTypes: { type: Array, default: () => [] },
});

const columns = [
    { key: 'title', label: 'Title' },
    { key: 'type', label: 'Type' },
    { key: 'position', label: 'Position' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '' },
];

const showAddDialog = ref(false);

const availableTypes = [
    { value: 'event_info', label: 'Event Info' },
    { value: 'custom_markdown', label: 'Custom Markdown' },
    { value: 'connection_strip', label: 'Connection Strip' },
    { value: 'bandwidth', label: 'Bandwidth' },
    { value: 'network_stats', label: 'Network Stats' },
    { value: 'dns_filter', label: 'DNS Filter' },
    { value: 'connection_status', label: 'Connection Status' },
];

const addableTypes = availableTypes.filter((t) => {
    if (props.singletonTypes.includes(t.value) && props.existingTypes.includes(t.value)) {
        return false;
    }
    return true;
});

async function deleteBlock(id) {
    if (!confirm('Are you sure you want to delete this block?')) return;
    await fetch(`/admin/content/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
    });
    window.location.reload();
}

async function toggleActive(block) {
    await fetch(`/admin/content/${block.id}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ is_active: !block.is_active }),
    });
    window.location.reload();
}

async function addBlock(type) {
    const label = availableTypes.find((t) => t.value === type)?.label ?? type;
    await fetch('/admin/content', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ type, title: label }),
    });
    showAddDialog.value = false;
    window.location.reload();
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Content Blocks
            </h1>
            <div class="flex gap-2">
                <Link
                    :href="route('admin.content.editor')"
                    data-testid="link-grid-editor"
                    class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium text-[var(--color-text)] transition-colors hover:border-[var(--color-border-hover)]"
                >
                    Grid Editor
                </Link>
                <button
                    data-testid="action-add-block"
                    class="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-white"
                    @click="showAddDialog = !showAddDialog"
                >
                    + Add Block
                </button>
            </div>
        </div>

        <!-- Add block dropdown -->
        <div v-if="showAddDialog" class="mb-4 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
            <p class="mb-2 text-sm font-medium text-[var(--color-text)]">Select block type:</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="t in addableTypes"
                    :key="t.value"
                    class="rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm text-[var(--color-text)] transition-colors hover:border-[var(--color-border-hover)]"
                    @click="addBlock(t.value)"
                >
                    {{ t.label }}
                </button>
            </div>
        </div>

        <EmptyState
            v-if="!blocks?.length"
            title="No content blocks"
            description="Content blocks will appear on the portal dashboard."
        />

        <div v-else class="mt-6">
            <DataTable :columns="columns" :rows="blocks">
                <template #row="{ row }">
                    <td class="py-2.5 text-[13px] font-medium text-[var(--color-text)]">
                        {{ row.title }}
                    </td>
                    <td class="py-2.5 text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.type }}
                    </td>
                    <td class="py-2.5 text-[13px] text-[var(--color-text-muted)]">
                        Col {{ row.grid_col }}, Row {{ row.grid_row }} ({{ row.col_span }}×{{ row.row_span }})
                    </td>
                    <td class="py-2.5">
                        <button
                            :data-testid="'toggle-active-' + row.id"
                            @click="toggleActive(row)"
                        >
                            <StatusPill
                                :status="row.is_active ? 'success' : 'neutral'"
                                :label="row.is_active ? 'Active' : 'Inactive'"
                            />
                        </button>
                    </td>
                    <td class="py-2.5 text-right">
                        <button
                            :data-testid="'action-delete-' + row.id"
                            class="text-xs text-[var(--color-danger)] hover:underline"
                            @click="deleteBlock(row.id)"
                        >
                            Delete
                        </button>
                    </td>
                </template>
            </DataTable>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Run tests**

```bash
npx vitest run tests/js/Pages/Admin/Content/Index.spec.js
```

Expected: All 6 pass

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Content/Index.vue tests/js/Pages/Admin/Content/Index.spec.js
git commit -m "feat: enhance admin content index with CRUD actions and grid editor link"
```

---

### Task 11: Grid Editor — Composable, Side Panel, and Editor Page

This is the largest task. It covers three files that work together:
1. `useGridEditor.js` — composable with all grid logic (drag, resize, collision detection)
2. `EditorSidePanel.vue` — slide-in panel for editing a block's settings
3. `Editor.vue` — the grid editor page that ties them together

**Files:**
- Create: `resources/js/composables/useGridEditor.js`
- Create: `resources/js/Components/Admin/Content/EditorSidePanel.vue`
- Modify: `resources/js/Pages/Admin/Content/Editor.vue`
- Create: `tests/js/composables/useGridEditor.spec.js`
- Create: `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`
- Create: `tests/js/Pages/Admin/Content/Editor.spec.js`

- [ ] **Step 1: Write failing tests for useGridEditor composable**

Create `tests/js/composables/useGridEditor.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { ref } from 'vue';
import { useGridEditor } from '@/composables/useGridEditor.js';

describe('useGridEditor', () => {
    function makeBlocks(positions) {
        return ref(
            positions.map((p, i) => ({
                id: i + 1,
                type: 'event_info',
                title: `Block ${i + 1}`,
                grid_col: p[0],
                grid_row: p[1],
                col_span: p[2] ?? 1,
                row_span: p[3] ?? 1,
            })),
        );
    }

    it('computes occupied cells from blocks', () => {
        const blocks = makeBlocks([[1, 1], [2, 1]]);
        const { isOccupied } = useGridEditor(blocks);
        expect(isOccupied(1, 1)).toBe(true);
        expect(isOccupied(2, 1)).toBe(true);
        expect(isOccupied(3, 1)).toBe(false);
    });

    it('accounts for col_span and row_span in occupied cells', () => {
        const blocks = makeBlocks([[1, 1, 2, 2]]);
        const { isOccupied } = useGridEditor(blocks);
        expect(isOccupied(1, 1)).toBe(true);
        expect(isOccupied(2, 1)).toBe(true);
        expect(isOccupied(1, 2)).toBe(true);
        expect(isOccupied(2, 2)).toBe(true);
        expect(isOccupied(3, 1)).toBe(false);
    });

    it('detects if a move would cause overlap', () => {
        const blocks = makeBlocks([[1, 1], [2, 1]]);
        const { canPlace } = useGridEditor(blocks);
        // Block 1 trying to move to (2,1) where block 2 is
        expect(canPlace(1, 2, 1, 1, 1)).toBe(false);
        // Block 1 moving to (3,1) which is free
        expect(canPlace(1, 3, 1, 1, 1)).toBe(true);
    });

    it('allows placing a block in its own current position', () => {
        const blocks = makeBlocks([[1, 1]]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(1, 1, 1, 1, 1)).toBe(true);
    });

    it('rejects placement outside grid columns (1-3)', () => {
        const blocks = makeBlocks([]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(null, 0, 1, 1, 1)).toBe(false);
        expect(canPlace(null, 4, 1, 1, 1)).toBe(false);
    });

    it('rejects col_span that exceeds grid width', () => {
        const blocks = makeBlocks([]);
        const { canPlace } = useGridEditor(blocks);
        expect(canPlace(null, 2, 1, 3, 1)).toBe(false); // col 2 + span 3 = col 5, exceeds 3
        expect(canPlace(null, 1, 1, 3, 1)).toBe(true);  // col 1 + span 3 = col 4, fits
    });

    it('computes total rows needed', () => {
        const blocks = makeBlocks([[1, 1], [1, 3, 1, 2]]);
        const { totalRows } = useGridEditor(blocks);
        expect(totalRows.value).toBe(4); // row 3 + row_span 2 = through row 4
    });

    it('moveBlock updates block position', () => {
        const blocks = makeBlocks([[1, 1]]);
        const { moveBlock } = useGridEditor(blocks);
        moveBlock(1, 3, 2);
        expect(blocks.value[0].grid_col).toBe(3);
        expect(blocks.value[0].grid_row).toBe(2);
    });

    it('resizeBlock updates block span', () => {
        const blocks = makeBlocks([[1, 1]]);
        const { resizeBlock } = useGridEditor(blocks);
        resizeBlock(1, 2, 3);
        expect(blocks.value[0].col_span).toBe(2);
        expect(blocks.value[0].row_span).toBe(3);
    });

    it('findFirstAvailable returns an empty cell', () => {
        const blocks = makeBlocks([[1, 1], [2, 1], [3, 1]]);
        const { findFirstAvailable } = useGridEditor(blocks);
        const pos = findFirstAvailable();
        expect(pos).toEqual({ col: 1, row: 2 });
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
npx vitest run tests/js/composables/useGridEditor.spec.js
```

Expected: FAIL

- [ ] **Step 3: Implement useGridEditor composable**

Create `resources/js/composables/useGridEditor.js`:

```js
import { computed } from 'vue';

export function useGridEditor(blocks) {
    const occupiedMap = computed(() => {
        const map = {};
        for (const block of blocks.value) {
            for (let c = block.grid_col; c < block.grid_col + block.col_span; c++) {
                for (let r = block.grid_row; r < block.grid_row + block.row_span; r++) {
                    map[`${c},${r}`] = block.id;
                }
            }
        }
        return map;
    });

    function isOccupied(col, row) {
        return `${col},${row}` in occupiedMap.value;
    }

    function canPlace(blockId, col, row, colSpan, rowSpan) {
        if (col < 1 || col > 3) return false;
        if (row < 1) return false;
        if (col + colSpan - 1 > 3) return false;

        for (let c = col; c < col + colSpan; c++) {
            for (let r = row; r < row + rowSpan; r++) {
                const key = `${c},${r}`;
                if (key in occupiedMap.value && occupiedMap.value[key] !== blockId) {
                    return false;
                }
            }
        }
        return true;
    }

    const totalRows = computed(() => {
        let max = 1;
        for (const block of blocks.value) {
            const end = block.grid_row + block.row_span - 1;
            if (end > max) max = end;
        }
        return max;
    });

    function moveBlock(blockId, col, row) {
        const block = blocks.value.find((b) => b.id === blockId);
        if (block) {
            block.grid_col = col;
            block.grid_row = row;
        }
    }

    function resizeBlock(blockId, colSpan, rowSpan) {
        const block = blocks.value.find((b) => b.id === blockId);
        if (block) {
            block.col_span = colSpan;
            block.row_span = rowSpan;
        }
    }

    function findFirstAvailable(colSpan = 1, rowSpan = 1) {
        for (let row = 1; row <= totalRows.value + 1; row++) {
            for (let col = 1; col <= 3; col++) {
                if (canPlace(null, col, row, colSpan, rowSpan)) {
                    return { col, row };
                }
            }
        }
        return { col: 1, row: 1 };
    }

    function getBlockAt(col, row) {
        const id = occupiedMap.value[`${col},${row}`];
        return id ? blocks.value.find((b) => b.id === id) : null;
    }

    return {
        isOccupied,
        canPlace,
        totalRows,
        moveBlock,
        resizeBlock,
        findFirstAvailable,
        getBlockAt,
        occupiedMap,
    };
}
```

- [ ] **Step 4: Run composable tests**

```bash
npx vitest run tests/js/composables/useGridEditor.spec.js
```

Expected: All 11 pass

- [ ] **Step 5: Write failing tests for EditorSidePanel**

Create `tests/js/Components/Admin/Content/EditorSidePanel.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import EditorSidePanel from '@/Components/Admin/Content/EditorSidePanel.vue';

describe('EditorSidePanel', () => {
    const block = {
        id: 1,
        type: 'event_info',
        title: 'Welcome',
        content: 'Hello world',
        col_span: 2,
        row_span: 1,
        is_active: true,
    };

    it('renders block type as read-only', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.text()).toContain('event_info');
    });

    it('renders title input with block title', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        const input = wrapper.find('[data-testid="panel-title-input"]');
        expect(input.element.value).toBe('Welcome');
    });

    it('renders content textarea for text blocks', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(true);
    });

    it('hides content textarea for non-text blocks', () => {
        const wrapper = mount(EditorSidePanel, {
            props: { block: { ...block, type: 'bandwidth' } },
        });
        expect(wrapper.find('[data-testid="panel-content-input"]').exists()).toBe(false);
    });

    it('renders col span selector', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.find('[data-testid="panel-col-span"]').exists()).toBe(true);
    });

    it('renders row span input', () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        expect(wrapper.find('[data-testid="panel-row-span"]').exists()).toBe(true);
    });

    it('emits save event with updated data', async () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        await wrapper.find('[data-testid="panel-title-input"]').setValue('Updated');
        await wrapper.find('[data-testid="panel-save"]').trigger('click');
        expect(wrapper.emitted('save')).toBeTruthy();
        expect(wrapper.emitted('save')[0][0].title).toBe('Updated');
    });

    it('emits delete event', async () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        await wrapper.find('[data-testid="panel-delete"]').trigger('click');
        expect(wrapper.emitted('delete')).toBeTruthy();
    });

    it('emits close event', async () => {
        const wrapper = mount(EditorSidePanel, { props: { block } });
        await wrapper.find('[data-testid="panel-close"]').trigger('click');
        expect(wrapper.emitted('close')).toBeTruthy();
    });
});
```

- [ ] **Step 6: Implement EditorSidePanel.vue**

Create `resources/js/Components/Admin/Content/EditorSidePanel.vue`:

```vue
<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    block: { type: Object, required: true },
});

const emit = defineEmits(['save', 'delete', 'close']);

const textTypes = ['event_info', 'custom_markdown'];

const title = ref(props.block.title);
const content = ref(props.block.content ?? '');
const colSpan = ref(props.block.col_span);
const rowSpan = ref(props.block.row_span);
const isActive = ref(props.block.is_active);

watch(
    () => props.block,
    (b) => {
        title.value = b.title;
        content.value = b.content ?? '';
        colSpan.value = b.col_span;
        rowSpan.value = b.row_span;
        isActive.value = b.is_active;
    },
);

function save() {
    emit('save', {
        id: props.block.id,
        title: title.value,
        content: content.value,
        col_span: colSpan.value,
        row_span: rowSpan.value,
        is_active: isActive.value,
    });
}
</script>

<template>
    <div
        data-testid="editor-side-panel"
        class="fixed inset-y-0 right-0 z-50 w-80 border-l border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg overflow-y-auto"
    >
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[var(--color-text)]">Edit Block</h3>
            <button
                data-testid="panel-close"
                class="text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                @click="emit('close')"
            >
                ✕
            </button>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >Block Type</label
            >
            <span class="text-[13px] text-[var(--color-text-secondary)]">{{ block.type }}</span>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >Title</label
            >
            <input
                v-model="title"
                data-testid="panel-title-input"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div v-if="textTypes.includes(block.type)" class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >Content</label
            >
            <textarea
                v-model="content"
                data-testid="panel-content-input"
                rows="4"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >Column Span</label
            >
            <div data-testid="panel-col-span" class="flex gap-2">
                <button
                    v-for="n in 3"
                    :key="n"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        colSpan === n
                            ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-accent)]'
                            : 'border-[var(--color-border)] text-[var(--color-text-muted)]'
                    "
                    @click="colSpan = n"
                >
                    {{ n }}
                </button>
            </div>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >Row Span</label
            >
            <input
                v-model.number="rowSpan"
                data-testid="panel-row-span"
                type="number"
                min="1"
                class="w-20 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div class="mb-4 flex items-center justify-between">
            <label class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                >Active</label
            >
            <button
                data-testid="panel-active-toggle"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                :class="isActive ? 'bg-[var(--color-success)]' : 'bg-[var(--color-surface-alt)]'"
                @click="isActive = !isActive"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200"
                    :class="isActive ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>

        <div class="flex gap-2">
            <button
                data-testid="panel-save"
                class="flex-1 rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
                @click="save"
            >
                Save Block
            </button>
            <button
                data-testid="panel-delete"
                class="rounded-md border border-[var(--color-danger)]/30 px-3 py-2 text-sm text-[var(--color-danger)]"
                @click="emit('delete', block.id)"
            >
                Delete
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 7: Run side panel tests**

```bash
npx vitest run tests/js/Components/Admin/Content/EditorSidePanel.spec.js
```

Expected: All 9 pass

- [ ] **Step 8: Write failing tests for Editor page**

Create `tests/js/Pages/Admin/Content/Editor.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Editor from '@/Pages/Admin/Content/Editor.vue';

vi.stubGlobal('route', vi.fn((name) => `/mock/${name}`));
vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) })));

describe('Admin Content Editor', () => {
    const defaultProps = {
        blocks: [
            { id: 1, type: 'event_info', title: 'Welcome', content: 'Hello', grid_col: 1, grid_row: 1, col_span: 2, row_span: 1, is_active: true, settings: null },
            { id: 2, type: 'bandwidth', title: 'Bandwidth', content: '', grid_col: 3, grid_row: 1, col_span: 1, row_span: 1, is_active: true, settings: null },
        ],
        singletonTypes: ['bandwidth', 'connection_strip', 'network_stats', 'dns_filter', 'connection_status'],
        existingTypes: ['event_info', 'bandwidth'],
    };

    it('renders the page title', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Grid Editor');
    });

    it('renders a 3-column grid', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="editor-grid"]').exists()).toBe(true);
    });

    it('renders blocks at their grid positions', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const block1 = wrapper.find('[data-testid="editor-block-1"]');
        expect(block1.exists()).toBe(true);
        expect(block1.attributes('style')).toContain('grid-column: 1 / span 2');
    });

    it('has a save layout button', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="action-save-layout"]').exists()).toBe(true);
    });

    it('has an add block button', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        expect(wrapper.find('[data-testid="action-add-block"]').exists()).toBe(true);
    });

    it('opens side panel on block click', async () => {
        const wrapper = mount(Editor, { props: defaultProps });
        await wrapper.find('[data-testid="editor-block-1"]').trigger('click');
        expect(wrapper.find('[data-testid="editor-side-panel"]').exists()).toBe(true);
    });

    it('shows block size indicator', () => {
        const wrapper = mount(Editor, { props: defaultProps });
        const block1 = wrapper.find('[data-testid="editor-block-1"]');
        expect(block1.text()).toContain('2×1');
    });
});
```

- [ ] **Step 9: Implement Editor.vue**

Replace `resources/js/Pages/Admin/Content/Editor.vue`:

```vue
<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EditorSidePanel from '@/Components/Admin/Content/EditorSidePanel.vue';
import { useGridEditor } from '@/composables/useGridEditor.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    singletonTypes: { type: Array, default: () => [] },
    existingTypes: { type: Array, default: () => [] },
});

const localBlocks = ref(JSON.parse(JSON.stringify(props.blocks)));
const selectedBlock = ref(null);
const hasChanges = ref(false);
const saving = ref(false);

const { totalRows, canPlace, moveBlock, resizeBlock, findFirstAvailable } = useGridEditor(localBlocks);

// Drag state
const dragging = ref(null);
const dragOver = ref(null);

function onDragStart(block, event) {
    dragging.value = block.id;
    event.dataTransfer.effectAllowed = 'move';
}

function onDragOver(col, row, event) {
    event.preventDefault();
    if (dragging.value && canPlace(dragging.value, col, row, getBlock(dragging.value).col_span, getBlock(dragging.value).row_span)) {
        dragOver.value = `${col},${row}`;
        event.dataTransfer.dropEffect = 'move';
    }
}

function onDrop(col, row) {
    if (dragging.value) {
        const block = getBlock(dragging.value);
        if (canPlace(dragging.value, col, row, block.col_span, block.row_span)) {
            moveBlock(dragging.value, col, row);
            hasChanges.value = true;
        }
    }
    dragging.value = null;
    dragOver.value = null;
}

function onDragEnd() {
    dragging.value = null;
    dragOver.value = null;
}

function getBlock(id) {
    return localBlocks.value.find((b) => b.id === id);
}

function selectBlock(block) {
    selectedBlock.value = block;
}

function closePanel() {
    selectedBlock.value = null;
}

async function saveBlock(data) {
    await fetch(`/admin/content/${data.id}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify(data),
    });
    const block = getBlock(data.id);
    if (block) {
        Object.assign(block, data);
    }
    selectedBlock.value = null;
}

async function deleteBlock(id) {
    if (!confirm('Delete this block?')) return;
    await fetch(`/admin/content/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
    });
    localBlocks.value = localBlocks.value.filter((b) => b.id !== id);
    selectedBlock.value = null;
    hasChanges.value = true;
}

async function saveLayout() {
    saving.value = true;
    await fetch(route('admin.content.layout.update'), {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({
            blocks: localBlocks.value.map((b) => ({
                id: b.id,
                grid_col: b.grid_col,
                grid_row: b.grid_row,
                col_span: b.col_span,
                row_span: b.row_span,
            })),
        }),
    });
    saving.value = false;
    hasChanges.value = false;
}

// Build grid cells: blocks at their positions + empty cells
function blockStyle(block) {
    return {
        gridColumn: `${block.grid_col} / span ${block.col_span}`,
        gridRow: `${block.grid_row} / span ${block.row_span}`,
    };
}

const displayRows = () => Math.max(totalRows.value + 1, 3);

const blockTypeColors = {
    event_info: 'rgba(34,197,94,0.3)',
    custom_markdown: 'rgba(34,197,94,0.3)',
    connection_strip: 'rgba(99,102,241,0.4)',
    connection_status: 'rgba(99,102,241,0.4)',
    bandwidth: 'rgba(59,130,246,0.3)',
    network_stats: 'rgba(251,191,36,0.3)',
    dns_filter: 'rgba(236,72,153,0.3)',
};
</script>

<template>
    <div>
        <div class="mb-4 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Grid Editor
            </h1>
            <div class="flex gap-2">
                <button
                    data-testid="action-add-block"
                    class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium text-[var(--color-text)]"
                >
                    + Add Block
                </button>
                <button
                    data-testid="action-save-layout"
                    class="rounded-md px-3 py-1.5 text-sm font-medium text-white"
                    :class="hasChanges ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-text-muted)]'"
                    :disabled="saving || !hasChanges"
                    @click="saveLayout"
                >
                    {{ saving ? 'Saving...' : 'Save Layout' }}
                    <span v-if="hasChanges && !saving" class="ml-1 inline-block h-2 w-2 rounded-full bg-white" />
                </button>
            </div>
        </div>

        <div
            data-testid="editor-grid"
            class="grid gap-3"
            :style="{
                gridTemplateColumns: 'repeat(3, 1fr)',
                gridTemplateRows: `repeat(${displayRows()}, minmax(80px, auto))`,
            }"
        >
            <!-- Rendered blocks -->
            <div
                v-for="block in localBlocks"
                :key="block.id"
                :data-testid="'editor-block-' + block.id"
                class="relative cursor-pointer rounded-md border-2 p-3"
                :style="{
                    ...blockStyle(block),
                    borderColor: blockTypeColors[block.type] ?? 'rgba(255,255,255,0.2)',
                    background: (blockTypeColors[block.type] ?? 'rgba(255,255,255,0.05)').replace(/[\d.]+\)$/, '0.08)'),
                }"
                draggable="true"
                @dragstart="onDragStart(block, $event)"
                @dragend="onDragEnd"
                @click="selectBlock(block)"
            >
                <span
                    class="text-[10px] font-bold tracking-wider uppercase"
                    :style="{ color: blockTypeColors[block.type] ?? '#888' }"
                >
                    {{ block.type.replace('_', ' ') }}
                </span>
                <div class="mt-1 text-xs text-[var(--color-text-secondary)]">{{ block.title }}</div>
                <div class="absolute top-2 right-2 text-[10px] text-[var(--color-text-muted)]">
                    {{ block.col_span }}×{{ block.row_span }}
                </div>
            </div>

            <!-- Empty drop zones -->
            <template v-for="row in displayRows()" :key="'row-' + row">
                <template v-for="col in 3" :key="'cell-' + col + '-' + row">
                    <div
                        v-if="!localBlocks.some((b) => col >= b.grid_col && col < b.grid_col + b.col_span && row >= b.grid_row && row < b.grid_row + b.row_span)"
                        class="rounded-md border-2 border-dashed border-[var(--color-border)]/30 flex items-center justify-center"
                        :style="{ gridColumn: col, gridRow: row }"
                        @dragover="onDragOver(col, row, $event)"
                        @drop="onDrop(col, row)"
                    >
                        <span class="text-[11px] text-[var(--color-text-muted)]/40">Drop here</span>
                    </div>
                </template>
            </template>
        </div>

        <!-- Side Panel -->
        <EditorSidePanel
            v-if="selectedBlock"
            :block="selectedBlock"
            @save="saveBlock"
            @delete="deleteBlock"
            @close="closePanel"
        />
    </div>
</template>
```

- [ ] **Step 10: Run all editor tests**

```bash
npx vitest run tests/js/composables/useGridEditor.spec.js tests/js/Components/Admin/Content/EditorSidePanel.spec.js tests/js/Pages/Admin/Content/Editor.spec.js
```

Expected: All pass

- [ ] **Step 11: Commit**

```bash
git add resources/js/composables/useGridEditor.js resources/js/Components/Admin/Content/EditorSidePanel.vue resources/js/Pages/Admin/Content/Editor.vue tests/js/composables/useGridEditor.spec.js tests/js/Components/Admin/Content/EditorSidePanel.spec.js tests/js/Pages/Admin/Content/Editor.spec.js
git commit -m "feat: add grid editor with drag-and-drop, side panel, and collision detection"
```

---

### Task 12: ContentBlockSeeder Update

**Files:**
- Modify: `database/seeders/ContentBlockSeeder.php`

- [ ] **Step 1: Update the seeder**

Replace `database/seeders/ContentBlockSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\ContentBlock;
use Illuminate\Database\Seeder;

class ContentBlockSeeder extends Seeder
{
    public function run(): void
    {
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
                'type' => 'event_info',
                'title' => 'Welcome to the LAN Party',
                'content' => 'Check the schedule and make the most of your time here. Have fun and play fair!',
                'grid_col' => 1,
                'grid_row' => 2,
                'col_span' => 2,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'network_stats',
                'title' => 'Network Stats',
                'content' => null,
                'grid_col' => 3,
                'grid_row' => 2,
                'col_span' => 1,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'bandwidth',
                'title' => 'Your Bandwidth',
                'content' => null,
                'grid_col' => 1,
                'grid_row' => 3,
                'col_span' => 1,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
            [
                'type' => 'dns_filter',
                'title' => 'DNS Ad Blocking',
                'content' => 'Toggle DNS filtering for your connection.',
                'grid_col' => 2,
                'grid_row' => 3,
                'col_span' => 1,
                'row_span' => 1,
                'is_active' => true,
                'settings' => null,
            ],
        ];

        foreach ($blocks as $block) {
            ContentBlock::firstOrCreate(
                ['type' => $block['type']],
                $block,
            );
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add database/seeders/ContentBlockSeeder.php
git commit -m "feat: update ContentBlockSeeder with new block types and grid positions"
```

---

### Task 13: Quality Checks and Final Verification

**Files:** All modified files

- [ ] **Step 1: Run Laravel Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

- [ ] **Step 3: Run Rector**

```bash
vendor/bin/rector process --dry-run
```

If changes suggested, apply with `vendor/bin/rector process`, then run Pint again.

- [ ] **Step 4: Run ESLint**

```bash
npx eslint resources/js/
```

- [ ] **Step 5: Run Prettier**

```bash
npx prettier --check resources/js/ resources/css/
```

If failures, fix with `npx prettier --write resources/js/ resources/css/`.

- [ ] **Step 6: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: All tests pass

- [ ] **Step 7: Run full JS test suite**

```bash
npx vitest run
```

Expected: All tests pass

- [ ] **Step 8: Commit any formatting/lint fixes**

```bash
git add -A
git commit -m "chore: apply formatting and lint fixes"
```
