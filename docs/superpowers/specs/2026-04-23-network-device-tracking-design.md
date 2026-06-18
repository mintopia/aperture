# Network Device Tracking & Cross-Referenced Admin Views

## Summary

Consolidate network device tracking across all discovery sources (DHCP, ARP, switch tables, user login, IPv6 JWT detection) into a unified data model. Every MAC address seen gets stored. Every IP address within managed ranges gets stored. All associations between IPs, MACs, users, switch ports, and DHCP leases are persisted and cross-referenced. A new audit log tracks every creation, link, and action. New MAC Address admin pages and cross-reference enhancements on existing pages provide a holistic view of the network.

## Sub-projects

This feature is delivered in two phases:

1. **Data Layer** — schema, migrations, models, ScanNetworkDevices refactor, user login cascade, OUI policy, Cisco optimization, audit log service
2. **Admin UI** — MAC address list/show pages, cross-reference enhancements on IP/User/SwitchPort pages, audit log page

Each sub-project gets its own implementation plan.

## Current State

### Models & Tables

- **IpAddress** — `address`, `mac_address_id` FK (single MAC), `user_id` FK (unused), `internet_enabled`, `rate_limit_enabled`, `dns_filtering_enabled`, `received`, `sent`, `comment`, `last_seen_at`, `expires_at`
- **MacAddress** — `mac_address`, `user_id` FK, `source`, `allowed`, `description`, `allowed_at`
- **UserIpAddress** — pivot: `user_id`, `ip_address_id`, `last_seen_at`
- **SwitchPortMac** — `switch_port_id`, `mac_address` (raw string, no FK), `vlan`, `last_seen_at`
- **DhcpLease** — value object only (`ip`, `mac`, `hostname`, `expires`), not persisted

### Discovery (ScanNetworkDevices)

The job currently mixes discovery with policy:
- Only creates IpAddress records for IPs whose MAC is already `allowed`
- Stores MACs only when they match OUI prefixes or are pre-existing and allowed
- Discards DHCP hostnames
- OUI prefixes and an on/off toggle stored in `IntegrationConfig` under `auto_allow`

### Cross-referencing

- IP Show page resolves switch port via live `IpAddressActionService::getPortInfo()`
- Switch Port Show page resolves MACs→IPs→Users via live `MacAddressResolver` calls
- User Show page shows associated IPs but no MAC or switch port info
- No MAC Address admin pages exist

## Changes

### 1. Schema Changes

#### New table: `ip_address_mac_address`

Many-to-many pivot between IPs and MACs, with discovery metadata.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `ip_address_id` | bigint unsigned FK | → `ip_addresses.id`, cascade delete |
| `mac_address_id` | bigint unsigned FK | → `mac_addresses.id`, cascade delete |
| `source` | varchar(255) | Discovery source: `dhcp`, `arp`, `switch`, `auth` |
| `last_seen_at` | timestamp | Last time this pairing was observed |
| `created_at` | timestamp nullable | |
| `updated_at` | timestamp nullable | |

Unique index on `(ip_address_id, mac_address_id)`.

#### New table: `dhcp_leases`

Persisted DHCP lease records.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `ip_address_id` | bigint unsigned FK | → `ip_addresses.id`, cascade delete |
| `mac_address_id` | bigint unsigned FK | → `mac_addresses.id`, cascade delete |
| `hostname` | varchar(255) nullable | Client hostname from DHCP |
| `expires_at` | timestamp nullable | Lease expiry |
| `created_at` | timestamp nullable | |
| `updated_at` | timestamp nullable | |

Unique index on `(ip_address_id, mac_address_id)` — one active lease per IP+MAC pair. Updated on each scan.

#### New table: `audit_logs`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `action` | varchar(255) | e.g. `ip.created`, `mac.created`, `ip_mac.linked`, `ip.internet_enabled`, `user.login` |
| `subject_type` | varchar(255) | Polymorphic morph type |
| `subject_id` | bigint unsigned | Entity acted upon |
| `related_type` | varchar(255) nullable | Second entity if linking |
| `related_id` | bigint unsigned nullable | |
| `actor_type` | varchar(255) nullable | `User` for admin/portal, null for system |
| `actor_id` | bigint unsigned nullable | |
| `process` | varchar(255) | `scan_network`, `portal_login`, `admin`, `oui_policy`, `ipv6_detection` |
| `metadata` | json nullable | Extra context (old/new values, source, etc.) |
| `created_at` | timestamp | |

Index on `(subject_type, subject_id)` for per-entity queries. Index on `action`. Index on `created_at`.

#### Modified table: `mac_addresses`

- **Drop** `allowed` column
- **Drop** `allowed_at` column
- **Keep** `user_id`, `mac_address`, `source`, `description`, timestamps

#### Modified table: `ip_addresses`

- **Drop** `mac_address_id` FK column (replaced by `ip_address_mac_address` pivot)
- **Drop** `user_id` FK column (unused — user ownership is via `user_ip_addresses`)

#### Modified table: `switch_port_macs`

- **Add** `mac_address_id` bigint unsigned FK nullable → `mac_addresses.id`, set null on delete
- **Keep** `mac_address` string column as denormalized value for display/lookup

#### Data migration

Within the single migration:
1. Create `ip_address_mac_address`, `dhcp_leases`, `audit_logs` tables.
2. Add `mac_address_id` FK to `switch_port_macs`.
3. Copy existing `ip_addresses.mac_address_id` relationships into `ip_address_mac_address` pivot (source: `auth`, `last_seen_at` from the IP's `last_seen_at`).
4. Populate `switch_port_macs.mac_address_id` by matching `switch_port_macs.mac_address` to `mac_addresses.mac_address`.
5. Drop `ip_addresses.mac_address_id` FK and column.
6. Drop `ip_addresses.user_id` FK and column.
7. Drop `mac_addresses.allowed` and `mac_addresses.allowed_at` columns.

### 2. Model Changes

#### IpAddress

```php
// Remove
public function macAddress(): BelongsTo  // old single FK

// Add
public function macAddresses(): BelongsToMany  // via ip_address_mac_address, withPivot('source', 'last_seen_at'), withTimestamps
public function dhcpLeases(): HasMany
public function currentMac(): ?MacAddress  // latest by pivot last_seen_at
public function auditLogs(): MorphMany  // polymorphic
```

Remove the `mac()` Attribute accessor. All consumers switch to `currentMac()` or `macAddresses`.

#### MacAddress

```php
// Remove (allowed/allowed_at no longer exist)
// 'allowed' cast, 'allowed_at' cast, 'allowed'/'allowed_at' from $fillable

// Add
public function ipAddresses(): BelongsToMany  // via ip_address_mac_address, withPivot('source', 'last_seen_at'), withTimestamps
public function dhcpLeases(): HasMany
public function switchPorts(): BelongsToMany  // via switch_port_macs
public function currentIp(): ?IpAddress  // latest by pivot last_seen_at
public function currentHostname(): ?string  // from latest DhcpLease
public function auditLogs(): MorphMany
```

Keep existing `user(): BelongsTo` and `ipAddresses` renamed from the old `HasMany` to `BelongsToMany`.

#### DhcpLease (NEW)

```php
class DhcpLease extends Model
{
    protected $fillable = ['ip_address_id', 'mac_address_id', 'hostname', 'expires_at'];

    public function ipAddress(): BelongsTo
    public function macAddress(): BelongsTo
}
```

#### IpAddressMacAddress (NEW)

Pivot model for the many-to-many.

```php
class IpAddressMacAddress extends Pivot
{
    public $incrementing = true;
    protected $table = 'ip_address_mac_address';
    protected $fillable = ['source', 'last_seen_at'];
}
```

#### AuditLog (NEW)

```php
class AuditLog extends Model
{
    const UPDATED_AT = null;  // no updated_at, only created_at

    protected $fillable = ['action', 'subject_type', 'subject_id', 'related_type', 'related_id', 'actor_type', 'actor_id', 'process', 'metadata'];

    public function subject(): MorphTo
    public function related(): MorphTo
    public function actor(): MorphTo

    public static function record(
        string $action,
        Model $subject,
        ?Model $related = null,
        ?Model $actor = null,
        string $process = 'system',
        ?array $metadata = null
    ): self
}
```

#### SwitchPortMac

```php
// Add
public function macAddressRecord(): BelongsTo  // FK to mac_addresses
```

#### User

No changes to relationships. `macAddresses(): HasMany` and `ips(): HasMany` already exist.

### 3. ScanNetworkDevices Refactor

The job is restructured into two phases.

#### Phase 1: Discovery (always runs)

**Step 1 — Collect from all sources:**
- `DhcpInterface::getLeases()` → IP, MAC, hostname, expiry
- `NetworkInventoryInterface::getArpTable()` → IP, MAC
- `NetworkInventoryInterface::getForwardingDatabase()` → MAC, port, VLAN (switch MAC table)

**Step 2 — Persist MACs:**
For every unique MAC seen across all sources, `MacAddress::firstOrCreate(['mac_address' => $normalized], ['source' => $source])`. Batch-load existing MACs upfront to avoid N+1.

**Step 3 — Persist IPs:**
For every unique IP seen, check `NetworkRangeService::isManaged($ip)`. If managed, `IpAddress::firstOrCreate(['address' => $ip], ['last_seen_at' => now()])`. If existing, touch `last_seen_at`.

**Step 4 — Link IP↔MAC:**
For every IP+MAC pair seen together (DHCP leases and ARP entries), create or update the `ip_address_mac_address` pivot with `source` and `last_seen_at`.

**Step 5 — Persist DHCP leases:**
For each DHCP lease, `DhcpLease::updateOrCreate(['ip_address_id' => ..., 'mac_address_id' => ...], ['hostname' => ..., 'expires_at' => ...])`.

**Step 6 — Link SwitchPort↔MAC:**
For each MAC seen on a switch port (from forwarding database), update `switch_port_macs` record with `mac_address_id` FK pointing to the MacAddress record.

**Step 7 — Audit logging:**
Log `ip.created`, `mac.created`, `ip_mac.linked`, `dhcp_lease.updated`, `switch_port_mac.linked` for all new records and link changes. Process: `scan_network`.

#### Phase 2: OUI Policy (runs if prefixes configured)

1. Read OUI prefixes from `Setting::get('network.oui_auto_allow')` — JSON array of normalized MAC prefix strings.
2. If empty, skip.
3. For each MacAddress whose normalized `mac_address` starts with a configured prefix:
   - Find all associated IPs via the pivot.
   - For each IP where `internet_enabled` is false, set it to true.
   - Audit log: `oui.auto_allowed` with the MAC and IP as subject/related.

#### Batch optimization

- Load all existing MacAddress records into a keyed collection before processing.
- Load all existing IpAddress records for the discovered IP set.
- Load existing pivot records to determine creates vs updates.
- Use `upsert()` where possible for bulk operations.

### 4. User Login Cascade

When `User::addIp($clientIp)` is called (portal login, captive portal, etc.):

1. **Existing behavior:** Create/update IpAddress and UserIpAddress records, apply user policy. (Unchanged.)
2. **New: MAC ownership assignment:**
   - Look up all MACs associated with this IP (via `ip_address_mac_address` pivot).
   - For each MAC where `user_id` is null, set `user_id` to this user.
   - Audit log: `mac.user_assigned`.
3. **New: IP cascade via shared MACs:**
   - For each MAC just assigned to this user, find all *other* IPs associated with that MAC (via pivot).
   - For each IP not already associated with a *different* user (check `user_ip_addresses`), call `addIp()` to associate it with this user.
   - This handles IPv6 linking: v4 and v6 addresses sharing a MAC get linked to the same user.
   - Audit log: `ip.user_cascaded`.
4. **Guard rails:**
   - Only assign MAC if `user_id` is null (don't steal from another user).
   - Only cascade IP if no other user owns it.
   - Managed range check applies to cascaded IPs (enforced by `addIp()`).
   - Depth-limited to one hop (IP→MAC→IPs). No recursive cascading.

### 5. OUI Configuration in Network Settings

The Network Settings page (`/admin/settings/network`) gets a new section.

**New setting key:** `network.oui_auto_allow`

**UI:** Textarea labeled "OUI Auto-Allow Prefixes", one prefix per line. Placeholder: `e.g. 00:50:F2`. Hint text: "MAC addresses matching these OUI prefixes will automatically receive internet access when discovered on a managed IP."

**Validation:** Each line must be 1-6 colon-separated hex octets. Regex: `/^([0-9A-Fa-f]{2}:){0,5}[0-9A-Fa-f]{2}$/`

**Storage:** JSON array of normalized (uppercased, colon-separated) prefix strings.

**Removal of old config:** Delete `IntegrationConfig` keys for `auto_allow` (`enabled`, `oui_prefixes`). The DhcpController integration settings page should no longer show these.

### 6. Cisco Switch Optimization

**Current behavior:** Per-port SSH commands: `show interface <port>` and `show running-config interface <port>`. Results in 2N SSH calls for N ports.

**New behavior:** Two bulk SSH commands:
- `show interface` — all interface status/counters in one output
- `show running-config | section ^interface` — all interface config blocks

Parse output by splitting on interface name patterns (`^GigabitEthernet\d`, `^FastEthernet\d`, `^TenGigabitEthernet\d`, etc.). Match each parsed block to the corresponding `SwitchPort` record by port name.

**Scope:** Cisco IOS adapter only. Other adapters unchanged. Public interface of the adapter stays the same — internal batching is an implementation detail.

### 7. Consumer Updates

All code reading the old `$ip->macAddress` (BelongsTo) or `$ip->mac` (Attribute accessor) must switch to the new relationships.

| File | Old Usage | New Usage |
|------|-----------|-----------|
| `IpAddress.php` | `mac()` accessor via `macAddress` | `currentMac()` method |
| `IpAddressController.php` | `$ip->mac` in show | `$ip->currentMac()?->mac_address` |
| `DashboardController.php` | `$ip?->mac` | `$ip?->currentMac()?->mac_address` |
| `SwitchPortController.php` | Live `MacAddressResolver` resolution | DB relationships via `SwitchPortMac.macAddressRecord` |
| `ScanNetworkDevices.php` | `$ip->mac_address_id = ...` | Pivot table operations |
| `Admin/Ips/Show.vue` | `ip.mac` | `ip.current_mac` |
| `Portal/Dashboard` blockContext | `macAddress` from accessor | From `currentMac()` |
| `BandwidthBlock.vue` | No change (doesn't use MAC) | No change |
| `resources/views/portal.blade.php` | `$ip?->mac` | `$ip?->currentMac()?->mac_address` |

### 8. Admin UI — MAC Address Pages (Sub-project 2)

#### Routes

```
GET  /admin/macs           → MacAddressController@index
GET  /admin/macs/{mac}     → MacAddressController@show
```

Route names: `admin.macs.index`, `admin.macs.show`

`{mac}` route model binding via `mac_address` column.

#### MAC Address Index Page

Filterable, sortable, paginated table.

| Column | Source | Filterable |
|--------|--------|-----------|
| MAC Address | `mac_address` | Yes |
| Hostname | Latest DhcpLease hostname | Yes |
| Current IP(s) | Via pivot, latest `last_seen_at` | Yes (by IP) |
| User | `user_id` → `user.nickname` | Yes (by nickname) |
| Switch / Port | Via `switch_port_macs` | No |
| Source | `source` column | No |
| Last Seen | Max `last_seen_at` across pivot records | Sortable |

#### MAC Address Show Page

**Header:** MAC address (mono) + hostname from latest DHCP lease

**Metadata strip:** User (linked to user show), Source, First Seen (`created_at`), Last Seen

**Sections:**

1. **Associated IPs** — DataTable: address (linked to IP show), internet status, source (from pivot), last seen, bandwidth (received/sent)
2. **DHCP Leases** — DataTable: IP (linked), hostname, expires, first seen, last updated
3. **Switch Ports** — DataTable: switch name (linked to switch show), port (linked to port show), VLAN, last seen
4. **Audit Log** — DataTable: action, timestamp, actor, metadata. Filtered to this MAC.

### 9. Admin UI — Cross-Reference Enhancements (Sub-project 2)

#### IP Address Show Page

Add after existing sections:
- **MAC Addresses** — DataTable of associated MACs (linked to MAC show), source, last seen
- **DHCP Leases** — DataTable: MAC (linked), hostname, expires, timestamps
- **Audit Log** — filtered to this IP

#### User Show Page

Add after existing IP list:
- **MAC Addresses** — DataTable: MAC (linked to MAC show), hostname (from latest lease), current IP(s), switch/port
- **Audit Log** — filtered to this user

#### Switch Port Show Page

Change `resolveConnectedDevices` to use DB relationships instead of live `MacAddressResolver` calls:
- Load `SwitchPortMac` records with `macAddressRecord.ipAddresses` and `macAddressRecord.user` relationships
- Hostname from `macAddressRecord.currentHostname()`
- No more live DHCP/ARP calls on page load — all data comes from the DB, populated by the scan job

### 10. Audit Log Admin Page (Sub-project 2)

#### Route

```
GET  /admin/audit-log  → AuditLogController@index
```

Route name: `admin.audit-log.index`

#### Page

Filterable, paginated table.

| Column | Notes |
|--------|-------|
| Timestamp | `created_at` |
| Action | e.g. `ip.created`, `mac.user_assigned` |
| Subject | Polymorphic link (IP address, MAC, User) |
| Related | Second entity if linking |
| Actor | User who performed it, or "System" |
| Process | `scan_network`, `portal_login`, `admin`, etc. |
| Details | Expandable metadata JSON |

Filters: action type dropdown, subject type, date range, process dropdown.

### 11. Sidebar Navigation

Add to the admin sidebar:

- **MAC Addresses** in the main navigation group (alongside IP Addresses, Users, Switches)
- **Audit Log** in the SYSTEM group

## Files Modified

### Sub-project 1 (Data Layer)

#### New files
- `database/migrations/XXXX_create_network_device_tracking_tables.php` — all schema changes in one migration
- `app/Models/DhcpLease.php` — new model
- `app/Models/AuditLog.php` — new model
- `app/Models/IpAddressMacAddress.php` — pivot model
- `app/Services/AuditLogService.php` — static `record()` helper (optional, could be on the model directly)
- `database/factories/DhcpLeaseFactory.php`
- `database/factories/AuditLogFactory.php`
- `database/factories/IpAddressMacAddressFactory.php`

#### Modified files
- `app/Models/IpAddress.php` — remove `macAddress()` BelongsTo, remove `mac()` accessor, add `macAddresses()` BelongsToMany, `dhcpLeases()` HasMany, `currentMac()`, `auditLogs()`
- `app/Models/MacAddress.php` — remove `allowed`/`allowed_at` from fillable/casts, add `ipAddresses()` BelongsToMany, `dhcpLeases()` HasMany, `switchPorts()` BelongsToMany, `currentIp()`, `currentHostname()`, `auditLogs()`
- `app/Models/SwitchPortMac.php` — add `macAddressRecord()` BelongsTo
- `app/Models/User.php` — update `addIp()` to include MAC ownership cascade
- `app/Jobs/ScanNetworkDevices.php` — full refactor: greedy discovery + OUI policy
- `app/Http/Controllers/Admin/NetworkSettingsController.php` — add OUI prefixes field
- `resources/js/Pages/Admin/Settings/Network.vue` — add OUI prefixes textarea
- `app/Http/Controllers/Portal/DashboardController.php` — update MAC access
- `app/Http/Controllers/PortalController.php` — update MAC access
- `app/Http/Controllers/CaptivePortalController.php` — no change needed (doesn't access MAC)
- `app/Http/Controllers/Admin/IpAddressController.php` — update show to use new relationships
- `resources/js/Pages/Admin/Ips/Show.vue` — update MAC display
- `resources/views/portal.blade.php` — update MAC access
- `app/Services/NetworkSwitch/CiscoSwitchAdapter.php` — bulk command optimization for `show interface` and `show running-config`

#### Removed/deprecated
- `IntegrationConfig` auto_allow keys — removed from DB and any admin UI

### Sub-project 2 (Admin UI)

#### New files
- `app/Http/Controllers/Admin/MacAddressController.php`
- `app/Http/Controllers/Admin/AuditLogController.php`
- `resources/js/Pages/Admin/Macs/Index.vue`
- `resources/js/Pages/Admin/Macs/Show.vue`
- `resources/js/Pages/Admin/AuditLog/Index.vue`

#### Modified files
- `routes/web.php` — add MAC and audit log routes
- `resources/js/Components/Admin/Sidebar.vue` — add MAC Addresses and Audit Log nav items
- `app/Http/Controllers/Admin/IpAddressController.php` — add MAC associations and DHCP leases to show
- `resources/js/Pages/Admin/Ips/Show.vue` — add MAC and DHCP sections, audit log
- `app/Http/Controllers/Admin/UserController.php` — add MAC addresses to show
- `resources/js/Pages/Admin/Users/Show.vue` — add MAC section, audit log
- `app/Http/Controllers/Admin/SwitchPortController.php` — replace live resolution with DB relationships
- `resources/js/Pages/Admin/Switches/Ports/Show.vue` — update connected devices display

## Files NOT Modified

- `app/Services/MacAddressResolver.php` — still useful for live resolution when DB data is stale; not removed but no longer primary data source for admin pages
- `app/Services/Interfaces/DhcpInterface.php` — interface unchanged, still used by scan job
- `app/Services/Interfaces/NetworkInventoryInterface.php` — interface unchanged
- `app/Models/SwitchConfig.php` — no changes
- `app/Models/SwitchPort.php` — no changes (relationship to MACs is via SwitchPortMac)

## Test Coverage

### Sub-project 1

#### Migration tests
- Data migration: existing `mac_address_id` records copied to pivot correctly
- Data migration: `switch_port_macs.mac_address_id` populated correctly
- Dropped columns no longer exist

#### Model relationship tests
- IpAddress ↔ MacAddress many-to-many CRUD
- IpAddress → DhcpLease hasMany
- MacAddress → DhcpLease hasMany
- SwitchPortMac → MacAddress belongsTo
- `currentMac()` returns latest by `last_seen_at`
- `currentIp()` returns latest by `last_seen_at`
- `currentHostname()` returns from latest DhcpLease

#### AuditLog tests
- `AuditLog::record()` creates correct record
- Polymorphic subject/related/actor resolve correctly
- `auditLogs()` morph relationship on IP, MAC, User

#### ScanNetworkDevices tests
- Discovery: all DHCP MACs persisted
- Discovery: all ARP MACs persisted
- Discovery: all in-range IPs persisted
- Discovery: out-of-range IPs skipped
- Discovery: IP↔MAC pivot created with correct source
- Discovery: DHCP leases persisted with hostname
- Discovery: SwitchPort↔MAC FK linked
- Discovery: existing records updated (last_seen_at touched)
- OUI policy: matching MAC → associated IPs get internet_enabled
- OUI policy: non-matching MAC → no change
- OUI policy: empty OUI config → policy skipped
- Audit logging for all creation/linking events

#### User login cascade tests
- Login assigns MAC ownership when MAC unowned
- Login does not steal MAC from another user
- Login cascades IP association via shared MAC
- Login does not cascade IP owned by different user
- Login cascade respects managed ranges
- Login cascade is depth-limited (no recursion)
- Audit logging for cascade events

#### OUI configuration tests
- Network settings page shows OUI field
- Valid OUI prefixes save correctly
- Invalid OUI format rejected
- Empty OUI clears setting

#### Consumer update tests
- DashboardController uses currentMac
- IP show uses new relationships
- Portal blade uses currentMac

### Sub-project 2

#### MAC Address controller tests
- Index page loads with MAC data
- Index filterable by MAC, hostname, IP, user
- Index pagination works
- Show page loads with all sections
- Non-admin cannot access

#### Audit Log controller tests
- Index page loads
- Filterable by action, subject, date range, process
- Pagination works
- Non-admin cannot access

#### Cross-reference tests
- IP show includes MAC associations section
- IP show includes DHCP lease section
- User show includes MAC addresses section
- Switch port show uses DB relationships

#### Vue component tests
- MAC Index renders table with correct columns
- MAC Show renders all sections
- Audit Log Index renders with filters
- IP Show renders new MAC/DHCP sections
- User Show renders new MAC section

## Out of Scope

- Historical tracking of MAC moves between ports (only current state stored)
- Automated cleanup of stale records (IPs/MACs not seen for extended periods)
- SNMP trap-based real-time MAC detection
- Non-Cisco switch bulk command optimization
- MAC address vendor/OUI name lookup (displaying manufacturer names)
- Export/import of audit logs
