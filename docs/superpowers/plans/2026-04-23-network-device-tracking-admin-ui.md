# Network Device Tracking — Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build admin pages for MAC addresses (list + show), a global audit log page, cross-reference enhancements on IP/User/SwitchPort show pages, and sidebar navigation updates.

**Architecture:** New `MacAddressController` and `AuditLogController` with Inertia-rendered Vue pages following existing patterns (DataTable, FilterBar, Pagination, MetadataStrip, SectionHeader). Cross-reference enhancements add new sections to existing show pages using DB relationships from Sub-Project 1. SwitchPort show replaces live `MacAddressResolver` calls with DB relationships.

**Tech Stack:** Laravel 12, Inertia.js, Vue 3, Tailwind CSS 4, PHPUnit, Vitest

**Spec:** `docs/superpowers/specs/2026-04-23-network-device-tracking-design.md` (sections 8-11)

**Prerequisite:** Sub-Project 1 (Data Layer) must be completed first.

---

## File Structure

### New files
- `app/Http/Controllers/Admin/MacAddressController.php` — index + show
- `app/Http/Controllers/Admin/AuditLogController.php` — index
- `resources/js/Pages/Admin/Macs/Index.vue` — MAC address list page
- `resources/js/Pages/Admin/Macs/Show.vue` — MAC address detail page
- `resources/js/Pages/Admin/AuditLog/Index.vue` — audit log list page
- `resources/js/Components/Icons/MacsIcon.vue` — sidebar icon
- `resources/js/Components/Icons/AuditLogIcon.vue` — sidebar icon
- `tests/Feature/Admin/MacAddressControllerTest.php`
- `tests/Feature/Admin/AuditLogControllerTest.php`
- `tests/Feature/Admin/CrossReferenceTest.php`

### Modified files
- `routes/web.php` — add MAC and audit log routes
- `resources/js/Components/Admin/Sidebar.vue` — add MAC Addresses and Audit Log nav items
- `app/Http/Controllers/Admin/IpAddressController.php` — add MAC + DHCP + audit data to show
- `resources/js/Pages/Admin/Ips/Show.vue` — add MAC, DHCP, and audit sections
- `app/Http/Controllers/Admin/UserController.php` — add MAC data to show
- `resources/js/Pages/Admin/Users/Show.vue` — add MAC section
- `app/Http/Controllers/Admin/SwitchPortController.php` — replace live resolution with DB relationships
- `resources/js/Pages/Admin/Switches/Ports/Show.vue` — update connected devices display

---

## Task 1: Routes and Sidebar Navigation

**Files:**
- Modify: `routes/web.php`
- Create: `resources/js/Components/Icons/MacsIcon.vue`
- Create: `resources/js/Components/Icons/AuditLogIcon.vue`
- Modify: `resources/js/Components/Admin/Sidebar.vue`

- [ ] **Step 1: Add routes to `routes/web.php`**

Inside the admin middleware group (after the Switch Port Management section, around line 136), add:

```php
// MAC Addresses
Route::get('/macs', [MacAddressController::class, 'index'])->name('macs.index');
Route::get('/macs/{mac}', [MacAddressController::class, 'show'])->name('macs.show');

// Audit Log
Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
```

Add the import at the top of the file:
```php
use App\Http\Controllers\Admin\MacAddressController;
use App\Http\Controllers\Admin\AuditLogController;
```

- [ ] **Step 2: Create MacsIcon.vue**

```vue
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-4 w-4"
        aria-hidden="true"
    >
        <rect x="2" y="6" width="20" height="12" rx="2" />
        <line x1="6" y1="10" x2="6" y2="14" />
        <line x1="10" y1="10" x2="10" y2="14" />
        <line x1="14" y1="10" x2="14" y2="14" />
        <line x1="18" y1="10" x2="18" y2="14" />
    </svg>
</template>
```

- [ ] **Step 3: Create AuditLogIcon.vue**

```vue
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-4 w-4"
        aria-hidden="true"
    >
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
        <line x1="16" y1="13" x2="8" y2="13" />
        <line x1="16" y1="17" x2="8" y2="17" />
        <polyline points="10 9 9 9 8 9" />
    </svg>
</template>
```

- [ ] **Step 4: Update Sidebar.vue**

In `resources/js/Components/Admin/Sidebar.vue`, add imports:

```javascript
import MacsIcon from '@/Components/Icons/MacsIcon.vue';
import AuditLogIcon from '@/Components/Icons/AuditLogIcon.vue';
```

In the `navGroups` array, add to the MANAGEMENT group (after the DHCP item):

```javascript
{ label: 'MAC Addresses', href: route('admin.macs.index'), icon: MacsIcon },
```

Add a new SYSTEM group after the CONTENT group:

```javascript
{
    label: 'SYSTEM',
    items: [
        { label: 'Audit Log', href: route('admin.audit-log.index'), icon: AuditLogIcon },
    ],
},
```

- [ ] **Step 5: Commit**

```bash
git add routes/web.php resources/js/Components/Icons/MacsIcon.vue resources/js/Components/Icons/AuditLogIcon.vue resources/js/Components/Admin/Sidebar.vue
git commit -m "feat: add MAC address and audit log routes with sidebar navigation"
```

---

## Task 2: MAC Address Controller

**Files:**
- Create: `app/Http/Controllers/Admin/MacAddressController.php`
- Test: `tests/Feature/Admin/MacAddressControllerTest.php`

- [ ] **Step 1: Write the controller test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MacAddressControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_loads_with_mac_data(): void
    {
        $admin = User::factory()->admin()->create();
        MacAddress::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.macs.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Macs/Index')
            ->has('macs.data', 3)
        );
    }

    public function test_index_filterable_by_mac_address(): void
    {
        $admin = User::factory()->admin()->create();
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:01']);
        MacAddress::factory()->create(['mac_address' => '11:22:33:44:55:66']);

        $response = $this->actingAs($admin)->get(route('admin.macs.index', ['mac' => 'AA:BB:CC']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
        );
    }

    public function test_index_filterable_by_hostname(): void
    {
        $admin = User::factory()->admin()->create();
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();
        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $ip->id,
            'hostname' => 'my-laptop',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.macs.index', ['hostname' => 'laptop']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
        );
    }

    public function test_index_filterable_by_ip_address(): void
    {
        $admin = User::factory()->admin()->create();
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '10.0.0.50']);
        $mac->ipAddresses()->attach($ip, ['source' => 'arp', 'last_seen_at' => now()]);
        MacAddress::factory()->create(); // unrelated MAC

        $response = $this->actingAs($admin)->get(route('admin.macs.index', ['ip' => '10.0.0']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
        );
    }

    public function test_index_filterable_by_user_nickname(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['nickname' => 'testuser']);
        MacAddress::factory()->create(['user_id' => $user->id]);
        MacAddress::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.macs.index', ['nickname' => 'testuser']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs.data', 1)
        );
    }

    public function test_index_pagination_works(): void
    {
        $admin = User::factory()->admin()->create();
        MacAddress::factory()->count(25)->create();

        $response = $this->actingAs($admin)->get(route('admin.macs.index', ['perPage' => 10]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs.data', 10)
        );
    }

    public function test_show_page_loads_with_all_sections(): void
    {
        $admin = User::factory()->admin()->create();
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();
        $mac->ipAddresses()->attach($ip, ['source' => 'dhcp', 'last_seen_at' => now()]);
        DhcpLease::factory()->create(['mac_address_id' => $mac->id, 'ip_address_id' => $ip->id, 'hostname' => 'test']);
        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);
        AuditLog::record(action: 'mac.created', subject: $mac, process: 'scan_network');

        $response = $this->actingAs($admin)->get(route('admin.macs.show', $mac));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Macs/Show')
            ->has('mac')
            ->has('ipAddresses')
            ->has('dhcpLeases')
            ->has('switchPorts')
            ->has('auditLogs')
        );
    }

    public function test_show_uses_mac_address_route_key(): void
    {
        $admin = User::factory()->admin()->create();
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $response = $this->actingAs($admin)->get('/admin/macs/AA:BB:CC:DD:EE:FF');

        $response->assertOk();
    }

    public function test_non_admin_cannot_access_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.macs.index'));

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_access_show(): void
    {
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.macs.show', $mac));

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=MacAddressControllerTest
```

Expected: FAIL — controller doesn't exist.

- [ ] **Step 3: Create the MacAddressController**

```bash
php artisan make:class App/Http/Controllers/Admin/MacAddressController --no-interaction
```

Then write the content:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MacAddress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MacAddressController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'mac' => $request->input('mac', ''),
            'hostname' => $request->input('hostname', ''),
            'nickname' => $request->input('nickname', ''),
            'ip' => $request->input('ip', ''),
            'order' => 'created_at',
            'direction' => 'desc',
        ];

        $query = MacAddress::query()->with(['user', 'dhcpLeases', 'ipAddresses']);

        if ($filters->mac !== '') {
            $query->where('mac_address', 'LIKE', sprintf('%%%s%%', $filters->mac));
        }

        if ($filters->hostname !== '') {
            $query->whereHas('dhcpLeases', function ($q) use ($filters): void {
                $q->where('hostname', 'LIKE', sprintf('%%%s%%', $filters->hostname));
            });
        }

        if ($filters->nickname !== '') {
            $query->whereHas('user', function ($q) use ($filters): void {
                $q->where('nickname', 'LIKE', sprintf('%%%s%%', $filters->nickname));
            });
        }

        if ($filters->ip !== '') {
            $query->whereHas('ipAddresses', function ($q) use ($filters): void {
                $q->where('address', 'LIKE', sprintf('%%%s%%', $filters->ip));
            });
        }

        $orderBy = ['mac_address', 'source', 'created_at'];
        if (in_array($request->input('order'), $orderBy)) {
            $filters->order = $request->input('order');
        }

        if (in_array($request->input('direction'), ['asc', 'desc'])) {
            $filters->direction = $request->input('direction');
        }

        $macs = $query->orderBy($filters->order, $filters->direction)
            ->paginate($filters->perPage)
            ->appends((array) $filters);

        // Transform for frontend
        $macs->getCollection()->transform(function (MacAddress $mac) {
            return [
                'id' => $mac->id,
                'mac_address' => $mac->mac_address,
                'hostname' => $mac->currentHostname(),
                'current_ips' => $mac->ipAddresses
                    ->sortByDesc('pivot.last_seen_at')
                    ->take(3)
                    ->map(fn ($ip) => ['id' => $ip->id, 'address' => $ip->address])
                    ->values(),
                'user' => $mac->user ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
                'source' => $mac->source,
                'created_at' => $mac->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('Admin/Macs/Index', [
            'macs' => $macs,
            'filters' => $filters,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'MAC Addresses'],
            ],
        ]);
    }

    public function show(MacAddress $mac): Response
    {
        $ipAddresses = $mac->ipAddresses()
            ->orderByPivot('last_seen_at', 'desc')
            ->get()
            ->map(fn ($ip) => [
                'id' => $ip->id,
                'address' => $ip->address,
                'internet_enabled' => $ip->internet_enabled,
                'source' => $ip->pivot->source,
                'last_seen_at' => $ip->pivot->last_seen_at?->toIso8601String(),
                'received' => $ip->received,
                'sent' => $ip->sent,
            ]);

        $dhcpLeases = $mac->dhcpLeases()
            ->with('ipAddress')
            ->latest()
            ->get()
            ->map(fn ($lease) => [
                'id' => $lease->id,
                'ip_address' => $lease->ipAddress ? ['id' => $lease->ipAddress->id, 'address' => $lease->ipAddress->address] : null,
                'hostname' => $lease->hostname,
                'expires_at' => $lease->expires_at?->toIso8601String(),
                'created_at' => $lease->created_at?->toIso8601String(),
                'updated_at' => $lease->updated_at?->toIso8601String(),
            ]);

        $switchPorts = $mac->switchPorts()
            ->with('switchConfig')
            ->get()
            ->map(fn ($port) => [
                'id' => $port->id,
                'port_name' => $port->port_name,
                'switch_id' => $port->switchConfig?->id,
                'switch_name' => $port->switchConfig?->name ?? $port->switchConfig?->hostname,
                'vlan' => $port->pivot->vlan,
                'last_seen_at' => $port->pivot->last_seen_at?->toIso8601String(),
            ]);

        $auditLogs = AuditLog::where('subject_type', $mac->getMorphClass())
            ->where('subject_id', $mac->id)
            ->orWhere(function ($q) use ($mac) {
                $q->where('related_type', $mac->getMorphClass())
                    ->where('related_id', $mac->id);
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->load('actor')
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? class_basename($log->actor_type) . ' ' . ($log->actor->nickname ?? $log->actor->name ?? '#' . $log->actor_id) : null,
                'process' => $log->process,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Macs/Show', [
            'mac' => [
                'id' => $mac->id,
                'mac_address' => $mac->mac_address,
                'hostname' => $mac->currentHostname(),
                'user' => $mac->user ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
                'source' => $mac->source,
                'description' => $mac->description,
                'created_at' => $mac->created_at?->toIso8601String(),
            ],
            'ipAddresses' => $ipAddresses,
            'dhcpLeases' => $dhcpLeases,
            'switchPorts' => $switchPorts,
            'auditLogs' => $auditLogs,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'MAC Addresses', 'href' => route('admin.macs.index')],
                ['label' => $mac->mac_address],
            ],
        ]);
    }
}
```

Also add route model binding to `MacAddress` model. In `app/Models/MacAddress.php`, add:

```php
public function getRouteKeyName(): string
{
    return 'mac_address';
}
```

- [ ] **Step 4: Run the controller test**

```bash
php artisan test --compact --filter=MacAddressControllerTest
```

Expected: Most tests pass (show page may fail until Vue pages exist, but controller tests should pass).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/MacAddressController.php app/Models/MacAddress.php tests/Feature/Admin/MacAddressControllerTest.php
git commit -m "feat: add MacAddressController with index and show actions

Filterable paginated index by MAC, hostname, user. Show page loads
associated IPs, DHCP leases, switch ports, and audit logs."
```

---

## Task 3: MAC Address Vue Pages — Index and Show

**Files:**
- Create: `resources/js/Pages/Admin/Macs/Index.vue`
- Create: `resources/js/Pages/Admin/Macs/Show.vue`

- [ ] **Step 1: Create Macs/Index.vue**

```vue
<script setup>
import { ref, computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    macs: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const searchQuery = ref(props.filters?.mac ?? '');

const columns = [
    { key: 'mac_address', label: 'MAC Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'current_ips', label: 'Current IP(s)' },
    { key: 'user', label: 'User' },
    { key: 'source', label: 'Source' },
];

const allMacs = computed(() => props.macs.data ?? []);

const filteredMacs = computed(() => {
    let result = allMacs.value;
    const q = searchQuery.value.toLowerCase().trim();

    if (q) {
        result = result.filter(
            (m) =>
                m.mac_address.toLowerCase().includes(q) ||
                (m.hostname && m.hostname.toLowerCase().includes(q)) ||
                (m.user && m.user.nickname.toLowerCase().includes(q)),
        );
    }

    return result;
});
</script>

<template>
    <div data-testid="macs-index-layout">
        <header data-testid="macs-index-header" class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    MAC Addresses
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                    All discovered MAC addresses across the network.
                </p>
            </div>
        </header>

        <section v-if="allMacs.length > 0" data-testid="macs-table-section" class="mt-6">
            <FilterBar
                :search="searchQuery"
                search-placeholder="Search MAC, hostname, user..."
                :total-count="allMacs.length"
                :filtered-count="filteredMacs.length"
                @update:search="searchQuery = $event"
            />

            <DataTable
                :columns="columns"
                :rows="filteredMacs"
                clickable
                :row-href="(row) => route('admin.macs.show', row.mac_address)"
                :row-aria-label="(row) => `Open MAC ${row.mac_address}`"
                empty-message="No MAC addresses match your search."
            >
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-text)]">
                        {{ row.mac_address }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.hostname ?? '\u2014' }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-for="(ip, i) in row.current_ips" :key="ip.id">
                            <span class="font-mono">{{ ip.address }}</span>
                            <span v-if="i < row.current_ips.length - 1">, </span>
                        </span>
                        <span v-if="!row.current_ips?.length">&mdash;</span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.user?.nickname ?? '\u2014' }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.source }}
                    </td>
                </template>
            </DataTable>
        </section>

        <div v-else data-testid="macs-empty" class="mt-12 text-center text-[13px] text-[var(--color-text-muted)]">
            No MAC addresses discovered yet.
        </div>

        <Pagination :paginator="macs" class="mt-4" />
    </div>
</template>
```

- [ ] **Step 2: Create Macs/Show.vue**

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import { formatBytes } from '@/helpers.js';
import { formatRelative } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    mac: { type: Object, default: () => ({}) },
    ipAddresses: { type: Array, default: () => [] },
    dhcpLeases: { type: Array, default: () => [] },
    switchPorts: { type: Array, default: () => [] },
    auditLogs: { type: Array, default: () => [] },
});

const ipColumns = [
    { key: 'address', label: 'Address' },
    { key: 'status', label: 'Status' },
    { key: 'source', label: 'Source' },
    { key: 'last_seen', label: 'Last Seen' },
    { key: 'bandwidth', label: 'Bandwidth' },
];

const dhcpColumns = [
    { key: 'ip', label: 'IP Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'expires', label: 'Expires' },
    { key: 'updated', label: 'Last Updated' },
];

const switchPortColumns = [
    { key: 'switch', label: 'Switch' },
    { key: 'port', label: 'Port' },
    { key: 'vlan', label: 'VLAN' },
    { key: 'last_seen', label: 'Last Seen' },
];

const auditColumns = [
    { key: 'action', label: 'Action' },
    { key: 'timestamp', label: 'Timestamp' },
    { key: 'actor', label: 'Actor' },
    { key: 'process', label: 'Process' },
    { key: 'metadata', label: 'Details' },
];
</script>

<template>
    <div data-testid="mac-show-layout" class="space-y-6">
        <!-- Header -->
        <header class="mb-2">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                style="font-variation-settings: 'opsz' 48"
            >
                <span class="font-mono">{{ mac.mac_address }}</span>
            </h1>
            <p v-if="mac.hostname" class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                {{ mac.hostname }}
            </p>
        </header>

        <MetadataStrip
            :items="[
                {
                    label: 'User',
                    value: mac.user?.nickname ?? 'Unassigned',
                    href: mac.user ? route('admin.users.show', mac.user.id) : undefined,
                },
                { label: 'Source', value: mac.source },
                { label: 'First Seen', value: formatRelative(mac.created_at) },
                { label: 'Description', value: mac.description ?? '\u2014' },
            ]"
        />

        <!-- Associated IPs -->
        <section data-testid="mac-ips-section">
            <SectionHeader title="Associated IPs" class="mt-5" />
            <DataTable
                :columns="ipColumns"
                :rows="ipAddresses"
                clickable
                :row-href="(row) => route('admin.ips.show', row.address)"
                :row-aria-label="(row) => `Open IP ${row.address}`"
                empty-message="No IP addresses associated."
            >
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-text)]">{{ row.address }}</td>
                    <td>
                        <StatusPill
                            :status="row.internet_enabled ? 'success' : 'muted'"
                            :label="row.internet_enabled ? 'Enabled' : 'Disabled'"
                        />
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.source }}</td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.last_seen_at) }}
                    </td>
                    <td class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatBytes(row.received) }} / {{ formatBytes(row.sent) }}
                    </td>
                </template>
            </DataTable>
        </section>

        <!-- DHCP Leases -->
        <section data-testid="mac-dhcp-section">
            <SectionHeader title="DHCP Leases" class="mt-5" />
            <DataTable :columns="dhcpColumns" :rows="dhcpLeases" empty-message="No DHCP leases found.">
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-text)]">
                        <Link
                            v-if="row.ip_address"
                            :href="route('admin.ips.show', row.ip_address.address)"
                            class="hover:underline"
                        >
                            {{ row.ip_address.address }}
                        </Link>
                        <span v-else>&mdash;</span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.hostname ?? '\u2014' }}</td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.expires_at) }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.updated_at) }}
                    </td>
                </template>
            </DataTable>
        </section>

        <!-- Switch Ports -->
        <section data-testid="mac-switch-ports-section">
            <SectionHeader title="Switch Ports" class="mt-5" />
            <DataTable :columns="switchPortColumns" :rows="switchPorts" empty-message="Not seen on any switch ports.">
                <template #row="{ row }">
                    <td class="text-[13px] text-[var(--color-text)]">
                        <Link
                            v-if="row.switch_id"
                            :href="route('admin.switches.show', row.switch_id)"
                            class="hover:underline"
                        >
                            {{ row.switch_name }}
                        </Link>
                        <span v-else>{{ row.switch_name ?? '\u2014' }}</span>
                    </td>
                    <td class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                        <Link
                            v-if="row.switch_id"
                            :href="route('admin.switches.ports.show', [row.switch_id, row.port_name])"
                            class="hover:underline"
                        >
                            {{ row.port_name }}
                        </Link>
                        <span v-else>{{ row.port_name }}</span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.vlan ?? '\u2014' }}</td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.last_seen_at) }}
                    </td>
                </template>
            </DataTable>
        </section>

        <!-- Audit Log -->
        <section data-testid="mac-audit-section">
            <SectionHeader title="Audit Log" class="mt-5" />
            <DataTable :columns="auditColumns" :rows="auditLogs" empty-message="No audit entries.">
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-text)]">{{ row.action }}</td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.created_at) }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.actor">{{ row.actor }}</span>
                        <span v-else>System</span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.process }}</td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.metadata" class="font-mono text-[11px]">{{ JSON.stringify(row.metadata) }}</span>
                        <span v-else>&mdash;</span>
                    </td>
                </template>
            </DataTable>
        </section>
    </div>
</template>
```

- [ ] **Step 3: Run the controller test again (now with Vue pages)**

```bash
php artisan test --compact --filter=MacAddressControllerTest
```

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Admin/Macs/Index.vue resources/js/Pages/Admin/Macs/Show.vue
git commit -m "feat: add MAC address index and show Vue pages

Index: filterable table with MAC, hostname, IPs, user, source.
Show: header, metadata strip, sections for IPs, DHCP leases,
switch ports, and audit log."
```

---

## Task 4: Audit Log Controller and Page

**Files:**
- Create: `app/Http/Controllers/Admin/AuditLogController.php`
- Create: `resources/js/Pages/Admin/AuditLog/Index.vue`
- Test: `tests/Feature/Admin/AuditLogControllerTest.php`

- [ ] **Step 1: Write the audit log controller test**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_loads(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get(route('admin.audit-log.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('logs.data', 1)
        );
    }

    public function test_filterable_by_action(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'mac.created', subject: $mac, process: 'scan_network');

        $response = $this->actingAs($admin)->get(route('admin.audit-log.index', ['action' => 'ip.created']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
        );
    }

    public function test_filterable_by_process(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'portal_login');

        $response = $this->actingAs($admin)->get(route('admin.audit-log.index', ['process' => 'scan_network']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
        );
    }

    public function test_pagination_works(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');
        }

        $response = $this->actingAs($admin)->get(route('admin.audit-log.index', ['perPage' => 10]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 10)
        );
    }

    public function test_non_admin_cannot_access(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.audit-log.index'));

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=AuditLogControllerTest
```

Expected: FAIL — controller doesn't exist.

- [ ] **Step 3: Create AuditLogController**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'action' => $request->input('action', ''),
            'process' => $request->input('process', ''),
            'subject_type' => $request->input('subject_type', ''),
            'date_from' => $request->input('date_from', ''),
            'date_to' => $request->input('date_to', ''),
        ];

        $query = AuditLog::query()->orderByDesc('created_at');

        if ($filters->action !== '') {
            $query->where('action', $filters->action);
        }

        if ($filters->process !== '') {
            $query->where('process', $filters->process);
        }

        if ($filters->subject_type !== '') {
            $query->where('subject_type', $filters->subject_type);
        }

        if ($filters->date_from !== '') {
            $query->where('created_at', '>=', $filters->date_from);
        }

        if ($filters->date_to !== '') {
            $query->where('created_at', '<=', $filters->date_to . ' 23:59:59');
        }

        $logs = $query->paginate($filters->perPage)->appends((array) $filters);

        $logs->getCollection()->transform(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'subject_type' => class_basename($log->subject_type),
            'subject_id' => $log->subject_id,
            'related_type' => $log->related_type ? class_basename($log->related_type) : null,
            'related_id' => $log->related_id,
            'actor_type' => $log->actor_type ? class_basename($log->actor_type) : null,
            'actor_id' => $log->actor_id,
            'process' => $log->process,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        // Get distinct values for filter dropdowns
        $actions = AuditLog::distinct()->pluck('action')->sort()->values();
        $processes = AuditLog::distinct()->pluck('process')->sort()->values();
        $subjectTypes = AuditLog::distinct()->pluck('subject_type')
            ->map(fn ($t) => ['value' => $t, 'label' => class_basename($t)])
            ->sortBy('label')
            ->values();

        return Inertia::render('Admin/AuditLog/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actionOptions' => $actions,
            'processOptions' => $processes,
            'subjectTypeOptions' => $subjectTypes,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Audit Log'],
            ],
        ]);
    }
}
```

- [ ] **Step 4: Create AuditLog/Index.vue**

```vue
<script setup>
import { ref, computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { formatRelative } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    logs: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    actionOptions: { type: Array, default: () => [] },
    processOptions: { type: Array, default: () => [] },
    subjectTypeOptions: { type: Array, default: () => [] },
});

const searchQuery = ref('');
const filterValues = ref({
    action: props.filters?.action ?? '',
    process: props.filters?.process ?? '',
    subject_type: props.filters?.subject_type ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
});

const filterDefinitions = computed(() => [
    {
        key: 'action',
        label: 'Action',
        options: props.actionOptions.map((a) => ({ value: a, label: a })),
    },
    {
        key: 'process',
        label: 'Process',
        options: props.processOptions.map((p) => ({ value: p, label: p })),
    },
    {
        key: 'subject_type',
        label: 'Subject Type',
        options: props.subjectTypeOptions,
    },
]);

const columns = [
    { key: 'timestamp', label: 'Timestamp' },
    { key: 'action', label: 'Action' },
    { key: 'subject', label: 'Subject' },
    { key: 'related', label: 'Related' },
    { key: 'actor', label: 'Actor' },
    { key: 'process', label: 'Process' },
];

const allLogs = computed(() => props.logs.data ?? []);

const filteredLogs = computed(() => {
    let result = allLogs.value;
    const q = searchQuery.value.toLowerCase().trim();
    if (q) {
        result = result.filter(
            (l) => l.action.toLowerCase().includes(q) || l.process.toLowerCase().includes(q),
        );
    }
    return result;
});
</script>

<template>
    <div data-testid="audit-log-index-layout">
        <header class="mb-2">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                style="font-variation-settings: 'opsz' 48"
            >
                Audit Log
            </h1>
            <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                System-wide audit trail of all entity changes and actions.
            </p>
        </header>

        <section data-testid="audit-log-table-section" class="mt-6">
            <FilterBar
                :search="searchQuery"
                search-placeholder="Search actions..."
                :filters="filterDefinitions"
                :filter-values="filterValues"
                :total-count="allLogs.length"
                :filtered-count="filteredLogs.length"
                @update:search="searchQuery = $event"
                @update:filter-values="filterValues = $event"
            />

            <DataTable :columns="columns" :rows="filteredLogs" empty-message="No audit log entries.">
                <template #row="{ row }">
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.created_at) }}
                    </td>
                    <td class="font-mono text-[13px] text-[var(--color-text)]">{{ row.action }}</td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.subject_type }} #{{ row.subject_id }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.related_type">{{ row.related_type }} #{{ row.related_id }}</span>
                        <span v-else>&mdash;</span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.actor_type">{{ row.actor_type }} #{{ row.actor_id }}</span>
                        <span v-else>System</span>
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.process }}</td>
                </template>
            </DataTable>
        </section>

        <Pagination :paginator="logs" class="mt-4" />
    </div>
</template>
```

- [ ] **Step 5: Run the audit log controller test**

```bash
php artisan test --compact --filter=AuditLogControllerTest
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AuditLogController.php resources/js/Pages/Admin/AuditLog/Index.vue tests/Feature/Admin/AuditLogControllerTest.php
git commit -m "feat: add AuditLog controller and index page

Filterable, paginated audit log. Filters by action, process.
Shows timestamp, action, subject, related entity, actor, process."
```

---

## Task 5: Cross-Reference Enhancements — IP Show Page

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`
- Modify: `resources/js/Pages/Admin/Ips/Show.vue`
- Test: `tests/Feature/Admin/CrossReferenceTest.php` (partial)

- [ ] **Step 1: Write the cross-reference test for IP show**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_show_includes_mac_associations(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macAddresses', 1)
        );
    }

    public function test_ip_show_includes_dhcp_leases(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        DhcpLease::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id]);

        $response = $this->actingAs($admin)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('dhcpLeases', 1)
        );
    }

    public function test_ip_show_includes_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        AuditLog::record(action: 'ip.created', subject: $ip, process: 'scan_network');

        $response = $this->actingAs($admin)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('auditLogs', 1)
        );
    }

    public function test_user_show_includes_mac_addresses(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        MacAddress::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macAddresses', 1)
        );
    }

    public function test_user_show_includes_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        AuditLog::record(action: 'user.login', subject: $user, process: 'portal_login');

        $response = $this->actingAs($admin)->get(route('admin.users.show', $user));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('auditLogs', 1)
        );
    }

    public function test_switch_port_show_uses_db_relationships(): void
    {
        $admin = User::factory()->admin()->create();
        $switchConfig = \App\Models\SwitchConfig::factory()->create();
        $port = \App\Models\SwitchPort::factory()->create(['switch_config_id' => $switchConfig->id]);
        $mac = MacAddress::factory()->create();
        \App\Models\SwitchPortMac::factory()->create([
            'switch_port_id' => $port->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.switches.ports.show', [$switchConfig, $port->port_name])
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('macs', 1)
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=CrossReferenceTest
```

Expected: FAIL — macAddresses/dhcpLeases/auditLogs not present in Inertia responses.

- [ ] **Step 3: Update IpAddressController show method**

In `app/Http/Controllers/Admin/IpAddressController.php`, in the `show()` method, add after loading `$users`:

```php
$macAddresses = $ip->macAddresses()
    ->orderByPivot('last_seen_at', 'desc')
    ->get()
    ->map(fn ($mac) => [
        'id' => $mac->id,
        'mac_address' => $mac->mac_address,
        'source' => $mac->pivot->source,
        'last_seen_at' => $mac->pivot->last_seen_at?->toIso8601String(),
        'user' => $mac->user ? ['id' => $mac->user->id, 'nickname' => $mac->user->nickname] : null,
    ]);

$dhcpLeases = $ip->dhcpLeases()
    ->with('macAddress')
    ->latest()
    ->get()
    ->map(fn ($lease) => [
        'id' => $lease->id,
        'mac_address' => $lease->macAddress ? ['id' => $lease->macAddress->id, 'mac_address' => $lease->macAddress->mac_address] : null,
        'hostname' => $lease->hostname,
        'expires_at' => $lease->expires_at?->toIso8601String(),
        'updated_at' => $lease->updated_at?->toIso8601String(),
    ]);

$auditLogs = AuditLog::where('subject_type', $ip->getMorphClass())
    ->where('subject_id', $ip->id)
    ->orderByDesc('created_at')
    ->limit(20)
    ->get()
    ->map(fn ($log) => [
        'id' => $log->id,
        'action' => $log->action,
        'process' => $log->process,
        'metadata' => $log->metadata,
        'created_at' => $log->created_at?->toIso8601String(),
    ]);
```

Add to the Inertia::render response:
```php
'macAddresses' => $macAddresses,
'dhcpLeases' => $dhcpLeases,
'auditLogs' => $auditLogs,
```

Add `use App\Models\AuditLog;` import.

- [ ] **Step 4: Update Ips/Show.vue**

Read the current Show.vue and add three new sections after the existing content. Add props:

```javascript
macAddresses: { type: Array, default: () => [] },
dhcpLeases: { type: Array, default: () => [] },
auditLogs: { type: Array, default: () => [] },
```

Add column definitions and sections following the same pattern as the MAC show page (DataTable with SectionHeader). Each section uses `data-testid` attributes: `ip-macs-section`, `ip-dhcp-section`, `ip-audit-section`.

The MAC Addresses section links to `route('admin.macs.show', row.mac_address)`. The DHCP section shows hostname and expiry. The audit log section shows action, process, timestamp.

- [ ] **Step 5: Run the cross-reference test**

```bash
php artisan test --compact --filter=CrossReferenceTest::test_ip_show
```

Expected: IP show tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/IpAddressController.php resources/js/Pages/Admin/Ips/Show.vue
git commit -m "feat: add MAC, DHCP, and audit log sections to IP show page"
```

---

## Task 6: Cross-Reference Enhancements — User Show Page

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Modify: `resources/js/Pages/Admin/Users/Show.vue`

- [ ] **Step 1: Update UserController show method**

In `app/Http/Controllers/Admin/UserController.php`, in the `show()` method, add:

```php
$macAddresses = $user->macAddresses()
    ->get()
    ->map(function (MacAddress $mac) {
        return [
            'id' => $mac->id,
            'mac_address' => $mac->mac_address,
            'hostname' => $mac->currentHostname(),
            'current_ips' => $mac->ipAddresses()
                ->orderByPivot('last_seen_at', 'desc')
                ->take(3)
                ->get()
                ->map(fn ($ip) => ['id' => $ip->id, 'address' => $ip->address]),
            'source' => $mac->source,
        ];
    });

$auditLogs = AuditLog::where('subject_type', $user->getMorphClass())
    ->where('subject_id', $user->id)
    ->orderByDesc('created_at')
    ->limit(20)
    ->get()
    ->map(fn ($log) => [
        'id' => $log->id,
        'action' => $log->action,
        'process' => $log->process,
        'metadata' => $log->metadata,
        'created_at' => $log->created_at?->toIso8601String(),
    ]);
```

Add `use App\Models\MacAddress;` and `use App\Models\AuditLog;` imports.

Add to the Inertia::render response:
```php
'macAddresses' => $macAddresses,
'auditLogs' => $auditLogs,
```

- [ ] **Step 2: Update Users/Show.vue**

Add new props:
```javascript
macAddresses: { type: Array, default: () => [] },
auditLogs: { type: Array, default: () => [] },
```

Add column definitions:
```javascript
const macColumns = [
    { key: 'mac_address', label: 'MAC Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'current_ips', label: 'Current IP(s)' },
    { key: 'source', label: 'Source' },
];

const auditColumns = [
    { key: 'action', label: 'Action' },
    { key: 'process', label: 'Process' },
    { key: 'timestamp', label: 'Timestamp' },
];
```

Add a new section after the IP Addresses section:

```html
<section data-testid="user-macs-section">
    <SectionHeader title="MAC Addresses" class="mt-5" />
    <DataTable
        :columns="macColumns"
        :rows="macAddresses"
        clickable
        :row-href="(row) => route('admin.macs.show', row.mac_address)"
        :row-aria-label="(row) => `Open MAC ${row.mac_address}`"
        empty-message="No MAC addresses associated."
    >
        <template #row="{ row }">
            <td class="font-mono text-[13px] text-[var(--color-text)]">{{ row.mac_address }}</td>
            <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.hostname ?? '\u2014' }}</td>
            <td class="text-[13px] text-[var(--color-text-secondary)]">
                <span v-for="(ip, i) in row.current_ips" :key="ip.id">
                    <span class="font-mono">{{ ip.address }}</span>
                    <span v-if="i < row.current_ips.length - 1">, </span>
                </span>
                <span v-if="!row.current_ips?.length">&mdash;</span>
            </td>
            <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.source }}</td>
        </template>
    </DataTable>
</section>

<!-- Audit Log -->
<section data-testid="user-audit-section">
    <SectionHeader title="Audit Log" class="mt-5" />
    <DataTable :columns="auditColumns" :rows="auditLogs" empty-message="No audit entries.">
        <template #row="{ row }">
            <td class="font-mono text-[13px] text-[var(--color-text)]">{{ row.action }}</td>
            <td class="text-[13px] text-[var(--color-text-secondary)]">{{ row.process }}</td>
            <td class="text-[13px] text-[var(--color-text-secondary)]">
                {{ formatRelative(row.created_at) }}
            </td>
        </template>
    </DataTable>
</section>
```

- [ ] **Step 3: Run the cross-reference test for user show**

```bash
php artisan test --compact --filter=CrossReferenceTest::test_user_show
```

Expected: Pass.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/UserController.php resources/js/Pages/Admin/Users/Show.vue
git commit -m "feat: add MAC addresses and audit log sections to user show page"
```

---

## Task 7: Cross-Reference Enhancements — Switch Port Show Page

**Files:**
- Modify: `app/Http/Controllers/Admin/SwitchPortController.php`
- Modify: `resources/js/Pages/Admin/Switches/Ports/Show.vue`

- [ ] **Step 1: Update SwitchPortController to use DB relationships**

In `app/Http/Controllers/Admin/SwitchPortController.php`, replace the `resolveConnectedDevices()` method with one that uses DB relationships instead of live `MacAddressResolver` calls:

```php
/**
 * Resolve connected devices using DB relationships.
 *
 * @param  Collection<int, SwitchPortMac>  $macs
 * @return array<int, array{mac_address: string, vlan: int|null, last_seen_at: string|null, mac_id: int|null, resolved_ips: array}>
 */
private function resolveConnectedDevices(Collection $macs): array
{
    // Eager load the macAddressRecord with its ipAddresses and user
    $macs->load([
        'macAddressRecord.ipAddresses',
        'macAddressRecord.user',
        'macAddressRecord.dhcpLeases',
    ]);

    $result = [];

    foreach ($macs as $mac) {
        $macRecord = $mac->macAddressRecord;

        $resolvedIps = [];
        if ($macRecord !== null) {
            $ips = $macRecord->ipAddresses()
                ->orderByPivot('last_seen_at', 'desc')
                ->get();

            foreach ($ips as $ip) {
                $latestUser = $ip->users()->with('user')->latest('last_seen_at')->first();

                $resolvedIps[] = [
                    'id' => $ip->id,
                    'ip' => $ip->address,
                    'hostname' => $macRecord->currentHostname(),
                    'user' => $latestUser?->user ? [
                        'id' => $latestUser->user->id,
                        'nickname' => $latestUser->user->nickname,
                    ] : null,
                ];
            }
        }

        $result[] = [
            'mac_address' => $mac->mac_address,
            'mac_id' => $macRecord?->id,
            'vlan' => $mac->vlan,
            'last_seen_at' => $this->toIso8601String($mac->last_seen_at),
            'resolved_ips' => $resolvedIps,
        ];
    }

    return $result;
}
```

Update the `show()` method call from:
```php
'macs' => $this->resolveConnectedDevices($macs, $this->macResolver),
```
To:
```php
'macs' => $this->resolveConnectedDevices($macs),
```

Remove the `MacAddressResolverInterface` constructor dependency if it's only used for `resolveConnectedDevices`. Check if it's used in other methods first — if so, keep it.

- [ ] **Step 2: Update Ports/Show.vue**

In `resources/js/Pages/Admin/Switches/Ports/Show.vue`, add MAC address links for each connected device entry. Where the MAC address is displayed, wrap it in a Link component:

```html
<Link
    v-if="device.mac_id"
    :href="route('admin.macs.show', device.mac_address)"
    class="font-mono hover:underline"
>
    {{ device.mac_address }}
</Link>
<span v-else class="font-mono">{{ device.mac_address }}</span>
```

Add the `Link` import from Inertia if not already present.

- [ ] **Step 3: Run the cross-reference test for switch port**

```bash
php artisan test --compact --filter=CrossReferenceTest::test_switch_port
```

Expected: Pass.

- [ ] **Step 4: Run existing SwitchPort controller tests**

```bash
php artisan test --compact --filter=SwitchPortControllerTest
```

Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/SwitchPortController.php resources/js/Pages/Admin/Switches/Ports/Show.vue
git commit -m "feat: replace live MAC resolution with DB relationships on switch port show

No more live DHCP/ARP calls on page load. All data comes from the DB,
populated by the scan job. MAC addresses link to MAC show page."
```

---

## Task 8: Fix IpAddress Index — Update eager loading

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php` (index method)

- [ ] **Step 1: Update the IP index controller**

In `app/Http/Controllers/Admin/IpAddressController.php`, in the `index()` method, the query currently does:
```php
$query = IpAddress::query()->with(['users.user', 'macAddress']);
```

Change to:
```php
$query = IpAddress::query()->with(['users.user']);
```

Since `macAddress` BelongsTo was removed, this will error if left. The index page doesn't show MAC data in its columns, so just remove the eager load.

- [ ] **Step 2: Run existing IP tests**

```bash
php artisan test --compact --filter=IpAddressControllerTest
```

Expected: All pass.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/IpAddressController.php
git commit -m "fix: remove macAddress eager load from IP index (relationship removed)"
```

---

## Task 9: Vitest Component Tests

**Files:**
- Create: `resources/js/Pages/Admin/Macs/__tests__/Index.test.js`
- Create: `resources/js/Pages/Admin/Macs/__tests__/Show.test.js`
- Create: `resources/js/Pages/Admin/AuditLog/__tests__/Index.test.js`

- [ ] **Step 1: Write MAC Index component test**

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../Index.vue';

const mockMacs = {
    data: [
        {
            id: 1,
            mac_address: 'AA:BB:CC:DD:EE:01',
            hostname: 'test-host',
            current_ips: [{ id: 1, address: '10.0.0.1' }],
            user: { id: 1, nickname: 'testuser' },
            source: 'dhcp',
            created_at: '2026-04-23T00:00:00+00:00',
        },
    ],
    links: {},
    meta: {},
};

describe('Macs/Index', () => {
    it('renders the page title', () => {
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: { stubs: ['AdminLayout', 'DataTable', 'FilterBar', 'Pagination'] },
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('MAC Addresses');
    });

    it('renders table with correct columns', () => {
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: { stubs: ['AdminLayout', 'FilterBar', 'Pagination'] },
        });
        expect(wrapper.find('[data-testid="macs-table-section"]').exists()).toBe(true);
    });

    it('shows empty state when no data', () => {
        const wrapper = mount(Index, {
            props: { macs: { data: [] }, filters: {} },
            global: { stubs: ['AdminLayout', 'DataTable', 'FilterBar', 'Pagination'] },
        });
        expect(wrapper.find('[data-testid="macs-empty"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Write MAC Show component test**

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../Show.vue';

const mockMac = {
    id: 1,
    mac_address: 'AA:BB:CC:DD:EE:01',
    hostname: 'test-host',
    user: { id: 1, nickname: 'testuser' },
    source: 'dhcp',
    description: null,
    created_at: '2026-04-23T00:00:00+00:00',
};

describe('Macs/Show', () => {
    it('renders all sections', () => {
        const wrapper = mount(Show, {
            props: {
                mac: mockMac,
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: { stubs: ['AdminLayout', 'MetadataStrip', 'DataTable', 'SectionHeader', 'StatusPill', 'Link'] },
        });
        expect(wrapper.find('[data-testid="mac-show-layout"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-ips-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-dhcp-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-switch-ports-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-audit-section"]').exists()).toBe(true);
    });

    it('renders MAC address in header', () => {
        const wrapper = mount(Show, {
            props: {
                mac: mockMac,
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: { stubs: ['AdminLayout', 'MetadataStrip', 'DataTable', 'SectionHeader', 'StatusPill', 'Link'] },
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toContain('AA:BB:CC:DD:EE:01');
    });
});
```

- [ ] **Step 3: Write Audit Log Index component test**

```javascript
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../Index.vue';

describe('AuditLog/Index', () => {
    it('renders the page title', () => {
        const wrapper = mount(Index, {
            props: {
                logs: { data: [] },
                filters: {},
                actionOptions: [],
                processOptions: [],
                subjectTypeOptions: [],
            },
            global: { stubs: ['AdminLayout', 'DataTable', 'FilterBar', 'Pagination'] },
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Audit Log');
    });

    it('renders table section', () => {
        const wrapper = mount(Index, {
            props: {
                logs: { data: [{ id: 1, action: 'ip.created', subject_type: 'IpAddress', subject_id: 1, related_type: null, related_id: null, actor_type: null, actor_id: null, process: 'scan_network', metadata: null, created_at: '2026-04-23T00:00:00+00:00' }] },
                filters: {},
                actionOptions: ['ip.created'],
                processOptions: ['scan_network'],
                subjectTypeOptions: [],
            },
            global: { stubs: ['AdminLayout', 'FilterBar', 'Pagination'] },
        });
        expect(wrapper.find('[data-testid="audit-log-table-section"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 4: Run Vitest**

```bash
npx vitest run resources/js/Pages/Admin/Macs/__tests__/ resources/js/Pages/Admin/AuditLog/__tests__/
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Macs/__tests__/ resources/js/Pages/Admin/AuditLog/__tests__/
git commit -m "test: add Vitest component tests for MAC and Audit Log pages"
```

---

## Task 10: Quality Checks

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

- [ ] **Step 4: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 5: Run JS quality checks**

```bash
npm run lint
npm run format:check
```

Fix any issues.

- [ ] **Step 6: Build frontend and verify**

```bash
npm run build
```

- [ ] **Step 7: Commit any quality fixes**

```bash
git add -A
git commit -m "fix: code quality and formatting for admin UI changes"
```

---

## Task 11: E2E Smoke Tests (if Playwright available)

**Files:**
- Potential new: `tests/e2e/mac-addresses.spec.ts`

- [ ] **Step 1: Check if Playwright is set up**

```bash
npx playwright test --list 2>/dev/null | head -5
```

If not available, skip this task.

- [ ] **Step 2: Write basic smoke tests**

If Playwright is available, write tests that:
- Navigate to MAC Addresses page via sidebar
- Verify the page loads with the data table
- Navigate to a MAC show page
- Verify all four sections render
- Navigate to Audit Log page via sidebar
- Verify it loads

- [ ] **Step 3: Run the smoke tests**

```bash
npx playwright test tests/e2e/mac-addresses.spec.ts
```

- [ ] **Step 4: Commit if tests were written**

```bash
git add tests/e2e/
git commit -m "test: add Playwright smoke tests for MAC and audit log pages"
```
