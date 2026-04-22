# Dashboard & Bandwidth Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix bandwidth graphs, add saturation/lightness to the accent color picker, fix markdown link styling, and add admin IP bandwidth graphs.

**Architecture:** Five independent changes that share the bandwidth and theming surface areas. Tasks are ordered by dependency: Prometheus/backend fixes first (unblocks graph display), then frontend chart fixes, then admin IP bandwidth endpoint+UI, then color picker, then markdown link fix.

**Tech Stack:** Laravel 12, PHP 8.5, Vue 3, Inertia.js, Chart.js, OKLCH colors, Prometheus, PHPUnit, Vitest

---

## File Structure

### Modified files:
- `app/Services/ValueObjects/UserBandwidth.php` — change download/upload arrays from int to float
- `app/Services/Prometheus/PrometheusTrafficMonitor.php` — rate window [5m]→[2m], float pipeline, ×8 bits conversion
- `app/Http/Controllers/Portal/StatsController.php` — cast floats for JSON
- `app/Http/Controllers/Admin/IpAddressController.php` — add bandwidth() method
- `app/Http/Controllers/Admin/ThemeSettingsController.php` — add chroma/lightness settings
- `app/Http/Middleware/InjectTheme.php` — share chroma/lightness
- `app/Http/Middleware/HandleInertiaRequests.php` — share chroma/lightness
- `resources/js/composables/useAccentHue.js` — rename to useAccentColor, add chroma/lightness params
- `resources/js/Pages/Admin/Settings/Theme.vue` — add S+L sliders
- `resources/js/Pages/Admin/Ips/Show.vue` — add bandwidth section
- `resources/js/Components/Blocks/CustomMarkdownBlock.vue` — fix prose link color
- `resources/css/app.css` — fix prose link specificity
- `routes/web.php` — add admin bandwidth route
- `tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php` — update for float pipeline + [2m]
- `tests/Feature/Portal/StatsControllerTest.php` — update for float values
- `tests/Feature/Admin/ThemeSettingsControllerTest.php` — add chroma/lightness tests
- `tests/Feature/Admin/IpAddressControllerTest.php` — add bandwidth endpoint test

### New files:
- `resources/js/composables/useAccentColor.js` — replaces useAccentHue.js

---

### Task 1: Change UserBandwidth to use floats for download/upload

**Files:**
- Modify: `app/Services/ValueObjects/UserBandwidth.php`

- [ ] **Step 1: Update the value object**

Change the `download` and `upload` array types from `int` to `float`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class UserBandwidth
{
    /**
     * @param  array<int, string>  $timestamps
     * @param  array<int, float>  $download
     * @param  array<int, float>  $upload
     */
    public function __construct(
        public int $received,
        public int $sent,
        public array $timestamps,
        public array $download,
        public array $upload,
    ) {}
}
```

- [ ] **Step 2: Run existing tests to verify nothing breaks**

Run: `php artisan test --compact tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php`
Expected: All tests pass (int values are valid floats)

- [ ] **Step 3: Commit**

```bash
git add app/Services/ValueObjects/UserBandwidth.php
git commit -m "refactor: change UserBandwidth download/upload arrays to float"
```

---

### Task 2: Fix PrometheusTrafficMonitor — rate window, float pipeline, bits conversion

**Files:**
- Modify: `app/Services/Prometheus/PrometheusTrafficMonitor.php`
- Modify: `tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php`

- [ ] **Step 1: Update the tests for new behavior**

In `tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php`, update all tests to:
1. Assert rate window is `[2m]` instead of `[5m]`
2. Assert download/upload values are floats (bytes/sec × 8 = bits/sec)
3. Assert total received/sent are ints (sum of bytes/sec values × step, approximated)

Key test changes:

`test_get_user_bandwidth_returns_user_bandwidth_value_object`:
- Change query assertions from `str_contains($query, 'rate(ntopng_host_bytes_rcvd{ip="10.0.0.1"}[5m])')` to `[2m]`
- Change expected download values: if Prometheus returns rate `'1024'` (bytes/sec), the value should be `1024.0 * 8 = 8192.0` (bits/sec)
- Change expected upload values similarly
- totalReceived and totalSent should be sums of raw bytes/sec values × step interval

`test_get_aggregate_stats_returns_aggregate_stats_value_object`:
- Change rate window assertions from `[5m]` to `[2m]`
- totalBandwidth should be multiplied by 8

All other rate window tests: change `[5m]` to `[2m]` in string assertions.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php`
Expected: Multiple failures due to old rate window and int values

- [ ] **Step 3: Update PrometheusTrafficMonitor implementation**

In `app/Services/Prometheus/PrometheusTrafficMonitor.php`:

a) Change rate window from `[5m]` to `[2m]` in `getUserBandwidth()`:
```php
$inQuery = sprintf(
    'sum(rate(%s{%s="%s"}[2m]))',
    $this->rcvdMetric,
    $this->ipLabel,
    $escapedIp,
);
$outQuery = sprintf(
    'sum(rate(%s{%s="%s"}[2m]))',
    $this->sentMetric,
    $this->ipLabel,
    $escapedIp,
);
```

b) Change download/upload value extraction to float × 8:
```php
$downloadValues = array_map(
    fn (array $point): float => (float) $point[1] * 8,
    $inPoints,
);

$uploadValues = array_map(
    fn (array $point): float => (float) $point[1] * 8,
    $outPoints,
);
```

c) Change totalReceived/totalSent to sum raw bytes × step, then approximate total bytes:
```php
$totalReceived = (int) round(array_sum(array_map(
    fn (array $point): float => (float) $point[1],
    $inPoints,
)) * $step);

$totalSent = (int) round(array_sum(array_map(
    fn (array $point): float => (float) $point[1],
    $outPoints,
)) * $step);
```

d) Change rate window in `getAggregateStats()`:
```php
$rcvdBandwidthData = $this->prometheus->query(
    sprintf('sum(rate(%s[2m]))', $this->rcvdMetric),
);
$sentBandwidthData = $this->prometheus->query(
    sprintf('sum(rate(%s[2m]))', $this->sentMetric),
);
```

And multiply totalBandwidth by 8:
```php
$totalBandwidth = ($this->extractScalarValue($rcvdBandwidthData) + $this->extractScalarValue($sentBandwidthData)) * 8;
```

e) Change rate window in `getTopTalkers()`:
```php
$query = sprintf(
    'topk(%d, sum by (%s) (rate(%s[2m])))',
    $limit,
    $this->ipLabel,
    $this->rcvdMetric,
);
```

And multiply the rate value by 8:
```php
$totalRate = (int) round((float) $item['value'][1] * 8);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add app/Services/Prometheus/PrometheusTrafficMonitor.php tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php
git commit -m "fix: use 2m rate window, float pipeline, and bytes-to-bits conversion"
```

---

### Task 3: Update StatsController and its tests for float values

**Files:**
- Modify: `app/Http/Controllers/Portal/StatsController.php`
- Modify: `tests/Feature/Portal/StatsControllerTest.php`

- [ ] **Step 1: Update StatsController tests**

In `tests/Feature/Portal/StatsControllerTest.php`, update `test_returns_bandwidth_from_traffic_monitor` to use float values for download/upload arrays in the mock return and assertions:

```php
$mock->shouldReceive('getUserBandwidth')
    ->once()
    ->andReturn(new UserBandwidth(
        received: 3072000,
        sent: 1536000,
        timestamps: ['1700000000', '1700000300'],
        download: [8192000.0, 16384000.0],
        upload: [4096000.0, 8192000.0],
    ));
```

And update the assertion:
```php
$response->assertOk()
    ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent'])
    ->assertJson([
        'totalReceived' => 3072000,
        'totalSent' => 1536000,
        'timestamps' => ['1700000000', '1700000300'],
        'download' => [8192000.0, 16384000.0],
        'upload' => [4096000.0, 8192000.0],
    ]);
```

Update `test_returns_empty_data_when_no_prometheus_data` similarly with float arrays.

- [ ] **Step 2: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Portal/StatsControllerTest.php`
Expected: All tests pass (JSON serialization handles floats natively)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Portal/StatsControllerTest.php
git commit -m "test: update StatsController tests for float bandwidth values"
```

---

### Task 4: Add admin IP bandwidth endpoint

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`
- Modify: `routes/web.php`
- Create or modify: `tests/Feature/Admin/IpAddressControllerTest.php`

- [ ] **Step 1: Write the failing test**

Add test methods to the existing `tests/Feature/Admin/IpAddressControllerTest.php` (or create it if it doesn't exist). The test should verify that an admin can fetch bandwidth data for a specific IP:

```php
public function test_admin_can_fetch_ip_bandwidth(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();
    $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

    $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getUserBandwidth')
            ->with('10.0.0.1', '24h')
            ->once()
            ->andReturn(new UserBandwidth(
                received: 1024000,
                sent: 512000,
                timestamps: ['1700000000'],
                download: [8192.0],
                upload: [4096.0],
            ));
    });

    $response = $this->actingAs($admin)->getJson('/admin/ips/' . $ip->id . '/bandwidth');

    $response->assertOk()
        ->assertJsonStructure(['timestamps', 'download', 'upload', 'totalReceived', 'totalSent']);
}

public function test_admin_bandwidth_accepts_range_parameter(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();
    $ip = IpAddress::factory()->create(['address' => '10.0.0.1']);

    $this->mock(TrafficMonitorInterface::class, function (MockInterface $mock): void {
        $mock->shouldReceive('getUserBandwidth')
            ->withArgs(fn (string $ipAddr, string $range): bool => $range === '4d')
            ->once()
            ->andReturn(new UserBandwidth(
                received: 0,
                sent: 0,
                timestamps: [],
                download: [],
                upload: [],
            ));
    });

    $response = $this->actingAs($admin)->getJson('/admin/ips/' . $ip->id . '/bandwidth?range=4d');

    $response->assertOk();
}

public function test_non_admin_cannot_fetch_ip_bandwidth(): void
{
    Queue::fake();
    $user = User::factory()->create();
    $ip = IpAddress::factory()->create();

    $this->actingAs($user)->getJson('/admin/ips/' . $ip->id . '/bandwidth')
        ->assertForbidden();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter='test_admin_can_fetch_ip_bandwidth|test_admin_bandwidth_accepts_range|test_non_admin_cannot_fetch_ip_bandwidth'`
Expected: FAIL — route not defined

- [ ] **Step 3: Add the route**

In `routes/web.php`, add after line 95 (after the `ips.limit` route):

```php
Route::get('ips/{ip}/bandwidth', [IpAddressController::class, 'bandwidth'])->name('ips.bandwidth');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/Admin/IpAddressController.php`, add the `bandwidth` method and the `TrafficMonitorInterface` import:

Add import:
```php
use App\Services\Interfaces\TrafficMonitorInterface;
use Illuminate\Http\JsonResponse;
```

Add method after `internet()`:
```php
public function bandwidth(Request $request, IpAddress $ip, TrafficMonitorInterface $trafficMonitor): JsonResponse
{
    $range = $request->query('range', '24h');

    $bandwidth = $trafficMonitor->getUserBandwidth($ip->address, is_string($range) ? $range : '24h');

    return response()->json([
        'timestamps' => $bandwidth->timestamps,
        'download' => $bandwidth->download,
        'upload' => $bandwidth->upload,
        'totalReceived' => $bandwidth->received,
        'totalSent' => $bandwidth->sent,
    ]);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter='test_admin_can_fetch_ip_bandwidth|test_admin_bandwidth_accepts_range|test_non_admin_cannot_fetch_ip_bandwidth'`
Expected: All 3 pass

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/IpAddressController.php routes/web.php tests/Feature/Admin/IpAddressControllerTest.php
git commit -m "feat: add admin IP bandwidth endpoint with range parameter"
```

---

### Task 5: Add bandwidth chart to admin IP show page

**Files:**
- Modify: `resources/js/Pages/Admin/Ips/Show.vue`

- [ ] **Step 1: Add bandwidth section to the admin IP show page**

Add imports and data fetching logic to the `<script setup>`:

```javascript
import { ref, onMounted, computed } from 'vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import { formatBytes } from '@/helpers.js';
```

Add state and fetch logic:
```javascript
const selectedRange = ref('24h');
const bandwidthData = ref({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 });
const bandwidthLoading = ref(true);
const ranges = ['1h', '24h', '4d'];

const chartSeries = computed(() => {
    const { timestamps, download, upload } = bandwidthData.value;
    if (!timestamps.length) return [];
    return [
        {
            label: 'Download',
            color: 'var(--color-success)',
            fill: true,
            data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: download[i] ?? 0 })),
        },
        {
            label: 'Upload',
            color: 'var(--color-info)',
            fill: true,
            data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: upload[i] ?? 0 })),
        },
    ];
});

async function fetchBandwidth() {
    bandwidthLoading.value = true;
    try {
        const response = await fetch(route('admin.ips.bandwidth', props.ip.id) + '?range=' + selectedRange.value);
        if (response.ok) {
            bandwidthData.value = await response.json();
        }
    } catch (_e) {
        // Will show empty state
    } finally {
        bandwidthLoading.value = false;
    }
}

function selectRange(range) {
    selectedRange.value = range;
    fetchBandwidth();
}

onMounted(() => {
    fetchBandwidth();
});
```

Add bandwidth section to the template, after the `MetadataStrip` and before the `SectionHeader title="Associated Users"`:

```html
<div class="mt-5">
    <div class="flex items-center justify-between">
        <SectionHeader title="Bandwidth" />
        <div class="flex gap-1" data-testid="bandwidth-range-selector">
            <button
                v-for="range in ranges"
                :key="range"
                type="button"
                :data-testid="'range-' + range"
                :class="
                    selectedRange === range
                        ? 'bg-[var(--color-accent-dim)] font-semibold text-[var(--color-primary)]'
                        : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text)]'
                "
                class="rounded-md px-3 py-1 text-[12px] font-medium transition-all"
                @click="selectRange(range)"
            >
                {{ range }}
            </button>
        </div>
    </div>
    <div class="mt-2 flex items-baseline gap-4">
        <div data-testid="bandwidth-download">
            <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">Down</span>
            <span class="ml-1 font-mono text-sm font-bold text-[var(--color-success)]">
                {{ formatBytes(bandwidthData.totalReceived) }}
            </span>
        </div>
        <div data-testid="bandwidth-upload">
            <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">Up</span>
            <span class="ml-1 font-mono text-sm font-bold text-[var(--color-info)]">
                {{ formatBytes(bandwidthData.totalSent) }}
            </span>
        </div>
    </div>
    <TimeSeriesChart
        :series="chartSeries"
        :loading="bandwidthLoading"
        y-axis-label="bps"
        height="200px"
        empty-message="No bandwidth data available"
        data-testid="admin-bandwidth-chart"
        class="mt-2"
    />
</div>
```

- [ ] **Step 2: Fix stale `ip.allowed` references in the same file**

The Show.vue still references `ip.allowed` (old column name) — fix to `ip.internet_enabled`:
- Line 35: `allow: props.ip.allowed ? 0 : 1` → `allow: props.ip.internet_enabled ? 0 : 1`
- Line 71: `ip.allowed ? 'action-revoke' : 'action-grant'` → `ip.internet_enabled ? ...`
- Lines 73-76: both `ip.allowed` → `ip.internet_enabled`
- Line 79: `ip.allowed ?` → `ip.internet_enabled ?`
- Lines 86-91: all `ip.allowed` → `ip.internet_enabled`
- Line 105: `ip.allowed ?` → `ip.internet_enabled`

- [ ] **Step 3: Verify in browser**

Start dev server if not running, navigate to `/admin/ips/{some-ip}`, verify the bandwidth chart renders with range selector buttons.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Admin/Ips/Show.vue
git commit -m "feat: add bandwidth chart with range selector to admin IP page"
```

---

### Task 6: Create useAccentColor composable (replaces useAccentHue)

**Files:**
- Create: `resources/js/composables/useAccentColor.js`
- Modify: `resources/js/composables/useAccentHue.js` (re-export for backward compat)

- [ ] **Step 1: Create the new composable**

Create `resources/js/composables/useAccentColor.js`:

```javascript
import { ref, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

export const ACCENT_PRESETS = [
    { name: 'Pink', hue: 350, l: 72, c: 0.19 },
    { name: 'Coral', hue: 20, l: 73, c: 0.17 },
    { name: 'Tangerine', hue: 55, l: 76, c: 0.16 },
    { name: 'Lime', hue: 135, l: 80, c: 0.18 },
    { name: 'Teal', hue: 185, l: 76, c: 0.12 },
    { name: 'Sky', hue: 230, l: 72, c: 0.14 },
    { name: 'Violet', hue: 295, l: 70, c: 0.18 },
    { name: 'Magenta', hue: 325, l: 70, c: 0.2 },
];

const DEFAULT_HUE = 55;
const DEFAULT_CHROMA = 0.19;
const DEFAULT_LIGHTNESS = 72;

export function applyAccentColor(hue, chroma, lightness, mode = 'dark') {
    const root = document.documentElement;

    let l = lightness;
    let c = chroma;

    if (mode === 'light') {
        l = Math.max(l - 21, 40);
        c = c + 0.02;
        root.style.setProperty('--color-primary', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-primary-hover', `oklch(${l - 7}% ${c + 0.02} ${hue})`);
        root.style.setProperty('--color-accent', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-accent-hover', `oklch(${l - 7}% ${c + 0.02} ${hue})`);
        root.style.setProperty('--color-accent-dim', `oklch(${l}% ${c} ${hue} / 0.1)`);
        root.style.setProperty('--color-accent-text', `oklch(99% 0.005 ${hue})`);
        root.style.setProperty('--color-glow', `oklch(${l}% ${c} ${hue} / 0.15)`);
    } else {
        root.style.setProperty('--color-primary', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-primary-hover', `oklch(${l - 7}% ${c + 0.03} ${hue})`);
        root.style.setProperty('--color-accent', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-accent-hover', `oklch(${l - 7}% ${c + 0.03} ${hue})`);
        root.style.setProperty('--color-accent-dim', `oklch(${l}% ${c} ${hue} / 0.14)`);
        root.style.setProperty('--color-accent-text', `oklch(98% 0.01 ${hue})`);
        root.style.setProperty('--color-glow', `oklch(${l}% ${c} ${hue} / 0.25)`);
    }
}

export function useAccentColor() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};
    const accentHue = ref(sharedTheme.accent_hue ?? DEFAULT_HUE);
    const accentChroma = ref(sharedTheme.accent_chroma ?? DEFAULT_CHROMA);
    const accentLightness = ref(sharedTheme.accent_lightness ?? DEFAULT_LIGHTNESS);

    function setAccentColor(hue, chroma, lightness, mode = 'dark') {
        accentHue.value = hue;
        accentChroma.value = chroma;
        accentLightness.value = lightness;
        applyAccentColor(hue, chroma, lightness, mode);
    }

    onMounted(() => {
        const mode = document.documentElement.getAttribute('data-mode') || 'dark';
        applyAccentColor(accentHue.value, accentChroma.value, accentLightness.value, mode);
    });

    return {
        accentHue,
        accentChroma,
        accentLightness,
        setAccentColor,
        presets: ACCENT_PRESETS,
    };
}
```

- [ ] **Step 2: Update useAccentHue.js to re-export from useAccentColor**

Replace `resources/js/composables/useAccentHue.js` contents:

```javascript
// Backward compatibility — re-exports from useAccentColor
export { ACCENT_PRESETS, useAccentColor as useAccentHue } from './useAccentColor.js';

export function applyAccentHue(hue, mode = 'dark') {
    // Legacy shim: look up preset or use defaults
    const { applyAccentColor, ACCENT_PRESETS: presets } = require('./useAccentColor.js');
    const preset = presets.find((p) => p.hue === hue);
    const l = preset ? preset.l : 72;
    const c = preset ? preset.c : 0.19;
    applyAccentColor(hue, c, l, mode);
}
```

Wait — ES modules can't use require. Let me fix this:

```javascript
import { ACCENT_PRESETS, applyAccentColor } from './useAccentColor.js';

export { ACCENT_PRESETS };
export { useAccentColor as useAccentHue } from './useAccentColor.js';

export function applyAccentHue(hue, mode = 'dark') {
    const preset = ACCENT_PRESETS.find((p) => p.hue === hue);
    const l = preset ? preset.l : 72;
    const c = preset ? preset.c : 0.19;
    applyAccentColor(hue, c, l, mode);
}
```

- [ ] **Step 3: Update useTheme.js**

In `resources/js/composables/useTheme.js`, find all references to `applyAccentHue` and `useAccentHue` and update imports to use the new composable. The `applyAccentHue` shim should handle backward compatibility, so check if useTheme imports it. If so, switch to `applyAccentColor` with the full signature where theme data is available.

- [ ] **Step 4: Verify existing functionality still works**

Run: `npx vitest run --reporter=verbose`
Expected: All existing JS tests pass

- [ ] **Step 5: Commit**

```bash
git add resources/js/composables/useAccentColor.js resources/js/composables/useAccentHue.js
git commit -m "feat: create useAccentColor composable with chroma and lightness support"
```

---

### Task 7: Add chroma/lightness to theme settings backend

**Files:**
- Modify: `app/Http/Controllers/Admin/ThemeSettingsController.php`
- Modify: `app/Http/Middleware/InjectTheme.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `tests/Feature/Admin/ThemeSettingsControllerTest.php`

- [ ] **Step 1: Update the tests**

Add to `tests/Feature/Admin/ThemeSettingsControllerTest.php`:

```php
public function test_admin_can_update_accent_chroma_and_lightness(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/theme', [
        'accent_hue' => 230,
        'accent_chroma' => 0.25,
        'accent_lightness' => 68,
        'theme_mode' => 'dark',
    ]);

    $response->assertRedirect();
    $this->assertEquals('0.25', Setting::get('theme.accent_chroma'));
    $this->assertEquals(68, Setting::get('theme.accent_lightness'));
}

public function test_accent_chroma_validates_range(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/theme', [
        'accent_hue' => 55,
        'accent_chroma' => 0.5,
        'accent_lightness' => 72,
        'theme_mode' => 'dark',
    ]);

    $response->assertSessionHasErrors('accent_chroma');
}

public function test_accent_lightness_validates_range(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/theme', [
        'accent_hue' => 55,
        'accent_chroma' => 0.19,
        'accent_lightness' => 100,
        'theme_mode' => 'dark',
    ]);

    $response->assertSessionHasErrors('accent_lightness');
}

public function test_theme_show_returns_chroma_and_lightness(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $this->saveSetting('theme.accent_chroma', 'Accent Chroma', '0.25');
    $this->saveSetting('theme.accent_lightness', 'Accent Lightness', 68);

    $response = $this->actingAs($admin)->get('/admin/settings/theme');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('settings.accent_chroma', 0.25)
        ->where('settings.accent_lightness', 68)
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Admin/ThemeSettingsControllerTest.php`
Expected: New tests fail

- [ ] **Step 3: Update ThemeSettingsController**

In `app/Http/Controllers/Admin/ThemeSettingsController.php`:

Update `show()` to include chroma and lightness:
```php
'accent_chroma' => (float) Setting::get('theme.accent_chroma', '0.19'),
'accent_lightness' => (int) Setting::get('theme.accent_lightness', 72),
```

Update `update()` validation rules — add after `accent_hue`:
```php
'accent_chroma' => 'nullable|numeric|min:0.01|max:0.37',
'accent_lightness' => 'nullable|integer|min:40|max:95',
```

Add save calls after existing `accent_hue` save:
```php
$this->saveSetting('theme.accent_chroma', 'Accent Chroma', (string) ($validated['accent_chroma'] ?? 0.19));
$this->saveSetting('theme.accent_lightness', 'Accent Lightness', (string) ($validated['accent_lightness'] ?? 72));
```

- [ ] **Step 4: Update InjectTheme middleware**

In `app/Http/Middleware/InjectTheme.php`, add after `$accentHue` line:
```php
$accentChroma = (float) Setting::get('theme.accent_chroma', '0.19');
$accentLightness = (int) Setting::get('theme.accent_lightness', 72);
```

Add shares:
```php
View::share('accentChroma', $accentChroma);
View::share('accentLightness', $accentLightness);
```

- [ ] **Step 5: Update HandleInertiaRequests middleware**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the `'theme'` array:
```php
'accent_chroma' => fn (): float => (float) Setting::get('theme.accent_chroma', '0.19'),
'accent_lightness' => fn (): int => (int) Setting::get('theme.accent_lightness', 72),
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Admin/ThemeSettingsControllerTest.php`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/ThemeSettingsController.php app/Http/Middleware/InjectTheme.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Admin/ThemeSettingsControllerTest.php
git commit -m "feat: add accent chroma and lightness to theme settings"
```

---

### Task 8: Add S+L sliders to Theme settings page

**Files:**
- Modify: `resources/js/Pages/Admin/Settings/Theme.vue`

- [ ] **Step 1: Update imports and form data**

Change imports:
```javascript
import { ACCENT_PRESETS, applyAccentColor } from '@/composables/useAccentColor.js';
```

Update `useForm`:
```javascript
const form = useForm({
    theme_mode: props.settings?.theme_mode ?? 'dark',
    accent_hue: props.settings?.accent_hue ?? 55,
    accent_chroma: props.settings?.accent_chroma ?? 0.19,
    accent_lightness: props.settings?.accent_lightness ?? 72,
    site_title: props.settings?.site_title ?? 'Aperture',
    custom_css: props.settings?.custom_css ?? '',
});
```

Store originals for cancel:
```javascript
const originalHue = ref(props.settings?.accent_hue ?? 55);
const originalChroma = ref(props.settings?.accent_chroma ?? 0.19);
const originalLightness = ref(props.settings?.accent_lightness ?? 72);
```

Update functions:
```javascript
function applyCurrentColor() {
    applyAccentColor(form.accent_hue, form.accent_chroma, form.accent_lightness, form.theme_mode);
}

function selectMode(mode) {
    form.theme_mode = mode;
    previewMode(mode);
    applyCurrentColor();
}

function selectPreset(preset) {
    form.accent_hue = preset.hue;
    form.accent_chroma = preset.c;
    form.accent_lightness = preset.l;
    applyCurrentColor();
}

function onHueInput(event) {
    form.accent_hue = Number(event.target.value);
    applyCurrentColor();
}

function onChromaInput(event) {
    form.accent_chroma = Number(Number(event.target.value).toFixed(2));
    applyCurrentColor();
}

function onLightnessInput(event) {
    form.accent_lightness = Number(event.target.value);
    applyCurrentColor();
}

function submit() {
    originalHue.value = form.accent_hue;
    originalChroma.value = form.accent_chroma;
    originalLightness.value = form.accent_lightness;
    form.put(route('admin.settings.theme.update'));
}
```

Add computed for active preset:
```javascript
const activePreset = computed(() =>
    ACCENT_PRESETS.find(
        (p) => p.hue === form.accent_hue && p.c === form.accent_chroma && p.l === form.accent_lightness,
    ),
);
```

Update `onBeforeUnmount`:
```javascript
onBeforeUnmount(() => {
    cancelPreview();
    applyAccentColor(
        originalHue.value,
        originalChroma.value,
        originalLightness.value,
        props.settings?.theme_mode ?? 'dark',
    );
});
```

- [ ] **Step 2: Update the template**

Replace the `FormField label="Accent Color"` section with:

```html
<FormField label="Accent Color" name="accent_hue">
    <div class="flex flex-wrap gap-3">
        <button
            v-for="preset in ACCENT_PRESETS"
            :key="preset.hue"
            type="button"
            :data-testid="'accent-preset-' + preset.hue"
            :title="preset.name"
            :style="{ backgroundColor: `oklch(${preset.l}% ${preset.c} ${preset.hue})` }"
            :class="[
                'h-8 w-8 rounded-full transition-all',
                activePreset && activePreset.hue === preset.hue
                    ? 'ring-2 ring-[var(--color-primary)] ring-offset-2 ring-offset-[var(--color-bg)]'
                    : 'hover:scale-110',
            ]"
            @click="selectPreset(preset)"
        />
    </div>

    <div class="mt-4 space-y-3">
        <div>
            <div class="mb-1 flex items-center justify-between">
                <span class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">Hue</span>
                <span class="font-mono text-xs text-[var(--color-text-muted)]">{{ form.accent_hue }}°</span>
            </div>
            <input
                type="range"
                min="0"
                max="360"
                :value="form.accent_hue"
                data-testid="accent-hue-slider"
                class="h-2 w-full cursor-pointer appearance-none rounded-full"
                style="
                    background: linear-gradient(
                        to right,
                        oklch(70% 0.18 0),
                        oklch(70% 0.18 60),
                        oklch(70% 0.18 120),
                        oklch(70% 0.18 180),
                        oklch(70% 0.18 240),
                        oklch(70% 0.18 300),
                        oklch(70% 0.18 360)
                    );
                "
                @input="onHueInput"
            />
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between">
                <span class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">Saturation</span>
                <span class="font-mono text-xs text-[var(--color-text-muted)]">{{ form.accent_chroma }}</span>
            </div>
            <input
                type="range"
                min="0.01"
                max="0.37"
                step="0.01"
                :value="form.accent_chroma"
                data-testid="accent-chroma-slider"
                class="h-2 w-full cursor-pointer appearance-none rounded-full"
                :style="{
                    background: `linear-gradient(to right, oklch(${form.accent_lightness}% 0.01 ${form.accent_hue}), oklch(${form.accent_lightness}% 0.19 ${form.accent_hue}), oklch(${form.accent_lightness}% 0.37 ${form.accent_hue}))`,
                }"
                @input="onChromaInput"
            />
        </div>

        <div>
            <div class="mb-1 flex items-center justify-between">
                <span class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">Lightness</span>
                <span class="font-mono text-xs text-[var(--color-text-muted)]">{{ form.accent_lightness }}%</span>
            </div>
            <input
                type="range"
                min="40"
                max="95"
                :value="form.accent_lightness"
                data-testid="accent-lightness-slider"
                class="h-2 w-full cursor-pointer appearance-none rounded-full"
                :style="{
                    background: `linear-gradient(to right, oklch(40% ${form.accent_chroma} ${form.accent_hue}), oklch(67% ${form.accent_chroma} ${form.accent_hue}), oklch(95% ${form.accent_chroma} ${form.accent_hue}))`,
                }"
                @input="onLightnessInput"
            />
        </div>

        <div class="flex items-center gap-3">
            <div
                data-testid="accent-preview-swatch"
                class="h-10 w-10 rounded-lg border border-[var(--color-border)]"
                :style="{ backgroundColor: `oklch(${form.accent_lightness}% ${form.accent_chroma} ${form.accent_hue})` }"
            />
            <span class="font-mono text-xs text-[var(--color-text-muted)]">
                oklch({{ form.accent_lightness }}% {{ form.accent_chroma }} {{ form.accent_hue }})
            </span>
        </div>
    </div>
</FormField>
```

- [ ] **Step 3: Verify in browser**

Navigate to `/admin/settings/theme`. Verify:
- Presets set all three sliders
- Slider backgrounds update dynamically
- Preview swatch reflects live color
- Saving persists all three values

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Admin/Settings/Theme.vue
git commit -m "feat: add saturation and lightness sliders to theme settings"
```

---

### Task 9: Fix markdown link accent color

**Files:**
- Modify: `resources/css/app.css`

- [ ] **Step 1: Check current prose link behavior**

Open the dashboard in a browser. Inspect a link inside a custom_markdown block. Check if `.prose a` rule is being applied or overridden.

- [ ] **Step 2: Fix CSS specificity**

In `resources/css/app.css`, the current rules are:
```css
.prose a {
    color: var(--color-accent);
}
.prose a:hover {
    opacity: 0.8;
}
```

If Tailwind v4 typography plugin styles override these, increase specificity. Replace with:
```css
.prose :where(a):not(:where([class~='not-prose'], [class~='not-prose'] *)) {
    color: var(--color-accent);
}
.prose :where(a):not(:where([class~='not-prose'], [class~='not-prose'] *)):hover {
    opacity: 0.8;
}
```

This matches Tailwind Typography's own specificity pattern to ensure the override works.

- [ ] **Step 3: Verify in browser**

Check that markdown links now display in the accent color on the dashboard.

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css
git commit -m "fix: ensure prose link color uses accent color with correct specificity"
```

---

### Task 10: Run full quality suite

**Files:** None (verification only)

- [ ] **Step 1: Run PHP quality checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
```

- [ ] **Step 2: Run JS quality checks**

```bash
npx eslint resources/js/
npx prettier --check resources/js/ resources/css/
npx vitest run
```

- [ ] **Step 3: Fix any failures**

Address any linting, formatting, or test failures.

- [ ] **Step 4: Final commit if needed**

```bash
git add -A
git commit -m "chore: apply formatting and linting fixes"
```
