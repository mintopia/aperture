# IP Show Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the admin IP detail page to add DNS filtering/rate limiting status and controls, MAC address, switch/port links in MetadataStrip, and a two-column users/bandwidth layout.

**Architecture:** Add a `dnsFilter()` action to the existing `IpAddressController`, update the `show()` method to pass switch link data and remove unused port status props, then rewrite the Vue template to the new layout. All existing components (MetadataStrip, DataTable, TimeSeriesChart, ConfirmModal) are reused as-is.

**Tech Stack:** Laravel 12, Inertia.js, Vue 3 (Composition API), Tailwind CSS 4

**Spec:** `docs/superpowers/specs/2026-04-23-ip-show-redesign-design.md`

---

### Task 1: Add DNS Filter Toggle Route and Controller Method

**Files:**
- Modify: `routes/web.php:98` (add route after existing `ips.limit` route)
- Modify: `app/Http/Controllers/Admin/IpAddressController.php` (add `dnsFilter()` method)
- Modify: `tests/Feature/Admin/IpAddressControllerTest.php` (add tests)

- [ ] **Step 1: Write failing tests for DNS filter toggle**

Add these tests to `tests/Feature/Admin/IpAddressControllerTest.php`, inside the `IpAddressControllerTest` class:

```php
public function test_admin_can_enable_dns_filter(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $ip = IpAddress::factory()->create();

    $response = $this->actingAs($admin)->post('/admin/ips/' . $ip->address . '/dns-filter', [
        'filter' => 1,
    ]);
    $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    $this->assertTrue($ip->fresh()->dns_filtering_enabled);
}

public function test_admin_can_disable_dns_filter(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $ip = IpAddress::factory()->create(['dns_filtering_enabled' => true]);

    $response = $this->actingAs($admin)->post('/admin/ips/' . $ip->address . '/dns-filter', [
        'filter' => 0,
    ]);
    $response->assertRedirect(route('admin.ips.show', ['ip' => $ip], false));
    $this->assertFalse($ip->fresh()->dns_filtering_enabled);
}

public function test_dns_filter_requires_filter_field(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $ip = IpAddress::factory()->create();

    $response = $this->actingAs($admin)->post('/admin/ips/' . $ip->address . '/dns-filter', []);
    $response->assertSessionHasErrors(['filter']);
}

public function test_dns_filter_rejects_non_boolean_filter(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $ip = IpAddress::factory()->create();

    $response = $this->actingAs($admin)->post('/admin/ips/' . $ip->address . '/dns-filter', [
        'filter' => 'notabool',
    ]);
    $response->assertSessionHasErrors(['filter']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_admin_can_enable_dns_filter|test_admin_can_disable_dns_filter|test_dns_filter_requires_filter_field|test_dns_filter_rejects_non_boolean_filter"`
Expected: 4 failures (route not found)

- [ ] **Step 3: Add the route**

In `routes/web.php`, after line 98 (`Route::post('ips/{ip}/limit', ...)`), add:

```php
Route::post('ips/{ip}/dns-filter', [IpAddressController::class, 'dnsFilter'])->name('ips.dns-filter');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/Admin/IpAddressController.php`, add after the `limit()` method:

```php
public function dnsFilter(Request $request, IpAddress $ip): RedirectResponse
{
    $request->validate(['filter' => 'required|boolean']);

    $ip->dns_filtering_enabled = $request->boolean('filter');
    $ip->save();

    $message = $ip->dns_filtering_enabled ? 'DNS filtering will be enabled for this IP' : 'DNS filtering will be disabled for this IP';

    return response()->redirectToRoute('admin.ips.show', ['ip' => $ip])->with('success', $message);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="test_admin_can_enable_dns_filter|test_admin_can_disable_dns_filter|test_dns_filter_requires_filter_field|test_dns_filter_rejects_non_boolean_filter"`
Expected: 4 passing

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/Admin/IpAddressController.php tests/Feature/Admin/IpAddressControllerTest.php
git commit -m "feat: add DNS filter toggle route for IP addresses"
```

---

### Task 2: Update Controller Show Method — Pass Switch Info, Remove Port Status

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php` (update `show()`)
- Modify: `tests/Feature/Admin/IpAddressControllerTest.php` (update show tests)

- [ ] **Step 1: Write failing test for switchInfo prop**

Add this test to `tests/Feature/Admin/IpAddressControllerTest.php`:

```php
public function test_ip_show_passes_switch_info_when_port_resolved(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('resolveIpToPort')
        ->andReturn(new ResolvedPort(ip: '10.0.0.1', mac: 'AA:BB:CC:DD:EE:FF', port: '1', switch: ''));
    $inventory->shouldReceive('getPortDetail')
        ->andReturn(new PortDetail(hostname: 'switch01', interface: 'Gi0/1', status: 'up', adminStatus: 'up', speed: 1000));
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    $ip = IpAddress::factory()->create();
    $switchConfig = SwitchConfig::factory()->create(['hostname' => 'switch01']);

    $response = $this->actingAs($admin)->get('/admin/ips/' . $ip->address);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Ips/Show')
        ->has('switchInfo')
        ->where('switchInfo.switchId', $switchConfig->id)
        ->where('switchInfo.switchName', 'switch01')
        ->where('switchInfo.portId', 'Gi0/1')
        ->missing('status')
        ->missing('shutdown')
    );
}

public function test_ip_show_passes_null_switch_info_when_no_port(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('resolveIpToPort')->andReturn(null);
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    $ip = IpAddress::factory()->create();

    $response = $this->actingAs($admin)->get('/admin/ips/' . $ip->address);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Ips/Show')
        ->where('switchInfo', null)
        ->missing('status')
        ->missing('shutdown')
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_ip_show_passes_switch_info|test_ip_show_passes_null_switch_info"`
Expected: FAIL (switchInfo prop missing, status/shutdown still present)

- [ ] **Step 3: Update the show() method**

Replace the `show()` method in `app/Http/Controllers/Admin/IpAddressController.php` with:

```php
public function show(IpAddress $ip): Response
{
    $switchInfo = null;
    $port = $this->ipAddressActionService->getPortInfo($ip);
    if ($port !== null) {
        $switchConfig = $this->resolveSwitchConfig($port);
        $switchInfo = [
            'switchId' => $switchConfig->id,
            'switchName' => $switchConfig->hostname,
            'portId' => $port->interface,
        ];
    }

    $users = $ip->users()->with('user')->get();

    return Inertia::render('Admin/Ips/Show', [
        'ip' => $ip,
        'port' => $port,
        'switchInfo' => $switchInfo,
        'users' => $users,
        'breadcrumbs' => [
            ['label' => 'Admin', 'href' => route('admin.home')],
            ['label' => 'IP Addresses', 'href' => route('admin.ips.index')],
            ['label' => $ip->address],
        ],
    ]);
}
```

- [ ] **Step 4: Run new tests to verify they pass**

Run: `php artisan test --compact --filter="test_ip_show_passes_switch_info|test_ip_show_passes_null_switch_info"`
Expected: 2 passing

- [ ] **Step 5: Update existing show tests that assert `status` and `shutdown`**

The existing tests `test_admin_can_view_ip_show_with_port_data`, `test_admin_can_view_ip_show_with_successful_switch_connection`, and `test_admin_can_view_ip_show_with_fallback_switch_config_when_hostname_not_in_db` assert `.where('status', ...)` and `.where('shutdown', true)`. Update these to assert `switchInfo` instead.

For `test_admin_can_view_ip_show_with_port_data` — the test creates a SwitchConfig with hostname `switch01`. Update the Inertia assertions to:

```php
->has('switchInfo')
->where('switchInfo.switchName', 'switch01')
->where('switchInfo.portId', 'Gi0/1')
```

Remove `.where('status', 'Unable to connect to switch')` and `.where('shutdown', true)`.

For `test_admin_can_view_ip_show_with_successful_switch_connection` — same approach. Update to assert `switchInfo` fields, remove `status`/`shutdown` assertions.

For `test_admin_can_view_ip_show_with_fallback_switch_config_when_hostname_not_in_db` — this test verifies the fallback switch config. Update to assert `switchInfo` is present (the fallback switch config's ID will be used), remove `status` assertion. The SwitchServiceFactory mock can be removed since we no longer call `getPortStatus()` in `show()`.

- [ ] **Step 6: Run all IP controller tests**

Run: `php artisan test --compact --filter="IpAddressControllerTest"`
Expected: All passing

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/IpAddressController.php tests/Feature/Admin/IpAddressControllerTest.php
git commit -m "feat: pass switchInfo prop from IP show, remove status/shutdown"
```

---

### Task 3: Rewrite the Vue Show Page

**Files:**
- Modify: `resources/js/Pages/Admin/Ips/Show.vue` (full rewrite of script + template)

- [ ] **Step 1: Update the script section**

Replace the full `<script setup>` section of `resources/js/Pages/Admin/Ips/Show.vue` with:

```javascript
<script setup>
import { ref, onMounted, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import { formatRelative } from '@/utils/dates';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ip: { type: Object, default: () => ({}) },
    port: { type: Object, default: () => ({}) },
    switchInfo: { type: Object, default: null },
    users: { type: Array, default: () => [] },
});

const showAccessModal = ref(false);
const togglingAccess = ref(false);

function toggleInternet() {
    showAccessModal.value = true;
}

function confirmToggleInternet() {
    togglingAccess.value = true;
    router.post(
        route('admin.ips.internet', props.ip.id),
        {
            allow: props.ip.internet_enabled ? 0 : 1,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingAccess.value = false;
                showAccessModal.value = false;
            },
        },
    );
}

function toggleRateLimit() {
    router.post(
        route('admin.ips.limit', props.ip.id),
        { limit: props.ip.rate_limit_enabled ? 0 : 1 },
        { preserveScroll: true },
    );
}

function toggleDnsFilter() {
    router.post(
        route('admin.ips.dns-filter', props.ip.id),
        { filter: props.ip.dns_filtering_enabled ? 0 : 1 },
        { preserveScroll: true },
    );
}

const userColumns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'last_seen', label: 'Last Seen' },
];

const selectedRange = ref('24h');
const bandwidthData = ref({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 });
const bandwidthLoading = ref(true);
const bandwidthError = ref(false);
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
    bandwidthError.value = false;
    try {
        const response = await fetch(route('admin.ips.bandwidth', props.ip.address) + '?range=' + selectedRange.value);
        if (response.ok) {
            bandwidthData.value = await response.json();
        } else {
            bandwidthError.value = true;
        }
    } catch (_e) {
        bandwidthError.value = true;
    } finally {
        bandwidthLoading.value = false;
    }
}

function selectRange(range) {
    selectedRange.value = range;
    fetchBandwidth();
}

const statusValue = computed(() => {
    if (props.ip.internet_enabled) return 'Allowed';
    if (props.users?.some((u) => u.user?.internet_enabled === false)) return 'Denied';
    return '\u2014';
});

onMounted(() => {
    fetchBandwidth();
});
</script>
```

- [ ] **Step 2: Replace the template section**

Replace the full `<template>` section with:

```html
<template>
    <div>
        <!-- Header -->
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                {{ ip.address }}
            </h1>
            <div class="flex items-center gap-2">
                <button
                    :data-testid="ip.internet_enabled ? 'action-revoke' : 'action-grant'"
                    :class="
                        ip.internet_enabled
                            ? 'border-[var(--color-danger)] bg-[var(--color-danger)]'
                            : 'border-[var(--color-success)] bg-[var(--color-success)]'
                    "
                    class="rounded-md border px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)]"
                    @click="toggleInternet"
                >
                    {{ ip.internet_enabled ? 'Revoke Access' : 'Grant Access' }}
                </button>
                <button
                    :data-testid="ip.rate_limit_enabled ? 'action-disable-rate-limit' : 'action-enable-rate-limit'"
                    class="rounded-md border border-[var(--color-border)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] hover:border-[var(--color-border-hover)] hover:text-[var(--color-text)]"
                    @click="toggleRateLimit"
                >
                    {{ ip.rate_limit_enabled ? 'Disable Rate Limit' : 'Enable Rate Limit' }}
                </button>
                <button
                    :data-testid="ip.dns_filtering_enabled ? 'action-disable-dns-filter' : 'action-enable-dns-filter'"
                    class="rounded-md border border-[var(--color-border)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] hover:border-[var(--color-border-hover)] hover:text-[var(--color-text)]"
                    @click="toggleDnsFilter"
                >
                    {{ ip.dns_filtering_enabled ? 'Disable DNS Filter' : 'Enable DNS Filter' }}
                </button>
            </div>
        </div>

        <!-- Confirm Modal -->
        <ConfirmModal
            :show="showAccessModal"
            :title="ip.internet_enabled ? 'Revoke Access?' : 'Grant Access?'"
            :message="
                ip.internet_enabled
                    ? 'This will deny internet access for this IP address.'
                    : 'This will restore internet access for this IP address.'
            "
            :confirm-label="ip.internet_enabled ? 'Revoke Access' : 'Grant Access'"
            :variant="ip.internet_enabled ? 'danger' : 'primary'"
            :loading="togglingAccess"
            @confirm="confirmToggleInternet"
            @cancel="showAccessModal = false"
        >
            <p v-if="users && users.length" class="mt-2 text-[13px] text-[var(--color-text-secondary)]">
                This IP has {{ users.length }} associated user(s).
            </p>
        </ConfirmModal>

        <!-- Metadata Strip -->
        <MetadataStrip
            :items="[
                { label: 'Status', value: statusValue },
                { label: 'MAC Address', value: ip.mac || '\u2014' },
                { label: 'Rate Limiting', value: ip.rate_limit_enabled ? 'Enabled' : '\u2014' },
                { label: 'DNS Filtering', value: ip.dns_filtering_enabled ? 'Enabled' : '\u2014' },
                {
                    label: 'Switch',
                    value: switchInfo ? switchInfo.switchName : '\u2014',
                    href: switchInfo ? route('admin.switches.show', switchInfo.switchId) : null,
                },
                {
                    label: 'Port',
                    value: switchInfo ? switchInfo.portId : '\u2014',
                    href: switchInfo
                        ? route('admin.switches.ports.show', [switchInfo.switchId, switchInfo.portId])
                        : null,
                },
                { label: 'Comment', value: ip.comment || '\u2014' },
            ]"
        />

        <!-- Two-column grid -->
        <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Left: Users -->
            <div>
                <SectionHeader title="Associated Users" />
                <DataTable
                    :columns="userColumns"
                    :rows="users ?? []"
                    clickable
                    :row-href="(row) => route('admin.users.show', row.user?.id)"
                    empty-message="No associated users"
                >
                    <template #row="{ row }">
                        <td class="font-mono text-[13px] text-[var(--color-primary)]">
                            {{ row.user?.nickname }}
                        </td>
                        <td class="text-[13px] text-[var(--color-text-secondary)]">
                            {{ formatRelative(row.last_seen_at) }}
                        </td>
                    </template>
                </DataTable>
            </div>

            <!-- Right: Bandwidth -->
            <div>
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
                        <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                            >Down</span
                        >
                        <span class="ml-1 font-mono text-sm font-bold text-[var(--color-success)]">
                            {{ formatBytes(bandwidthData.totalReceived) }}
                        </span>
                    </div>
                    <div data-testid="bandwidth-upload">
                        <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                            >Up</span
                        >
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
                <p
                    v-if="bandwidthError"
                    class="mt-2 text-[12px] text-[var(--color-danger)]"
                    data-testid="bandwidth-error"
                >
                    Failed to load bandwidth data
                </p>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Add `href` support to MetadataStrip**

The MetadataStrip component (`resources/js/Components/UI/MetadataStrip.vue`) does not currently support links. Add `href` support:

1. Add `import { Link } from '@inertiajs/vue3';` to the script section.
2. Update the type comment to include `href?: string`.
3. In the template, wrap the value `<span>` content: when `item.href` is set, render the slot default content inside a `<Link :href="item.href">` with appropriate link styling (`text-[var(--color-primary)] hover:underline`). When no `href`, render as before.

Replace the value `<span>` block:

```html
<span
    :class="[
        item.mono ? 'font-mono text-[14px]' : '',
        item.large ? 'text-sm font-semibold' : 'text-[15px]',
    ]"
    class="font-medium text-[var(--color-text)]"
>
    <slot :name="item.label" :item="item">
        <Link
            v-if="item.href"
            :href="item.href"
            class="text-[var(--color-primary)] hover:underline"
        >
            {{ item.value }}
        </Link>
        <template v-else>{{ item.value }}</template>
    </slot>
</span>
```

- [ ] **Step 4: Run Pint and ESLint**

```bash
vendor/bin/pint --dirty --format agent
npx eslint resources/js/Pages/Admin/Ips/Show.vue --fix
npx prettier --write resources/js/Pages/Admin/Ips/Show.vue
```

- [ ] **Step 5: Build frontend and verify page loads**

```bash
npm run build
```

Open `https://aperture.local.js42.io/admin/ips` in browser, navigate to an IP detail page, and verify:
- Three buttons top-right (filled danger/success + two ghost)
- MetadataStrip shows all 7 fields
- Two-column layout: users left, bandwidth right
- Switch port section is gone
- Switch/Port values link to the correct pages (or show "—" if no port info)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/Ips/Show.vue resources/js/Components/UI/MetadataStrip.vue
git commit -m "feat: redesign IP show page with two-column layout and new controls"
```

---

### Task 4: Quality Checks and Final Verification

**Files:**
- All modified files from Tasks 1-3

- [ ] **Step 1: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: All passing

- [ ] **Step 2: Run PHP quality tools**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

Expected: No errors

- [ ] **Step 3: Run JS quality tools**

```bash
npm run lint
npm run format:check
```

Expected: No errors

- [ ] **Step 4: Run Vitest**

```bash
npm run test
```

Expected: All passing

- [ ] **Step 5: Commit any formatting fixes**

```bash
git add -A
git commit -m "style: formatting fixes for IP show redesign"
```

(Skip if no changes)
