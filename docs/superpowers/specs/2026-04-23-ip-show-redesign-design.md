# IP Show Page Redesign

## Summary

Redesign the admin IP address detail page (`Admin/Ips/Show.vue`) to add DNS filtering and rate limiting status/controls, MAC address display, switch/port links, and a two-column layout for users and bandwidth.

## Current State

The page currently shows:
- Header: IP address title + Grant/Revoke Access button
- MetadataStrip: Status, Comment
- Bandwidth section: TimeSeriesChart with range selector (1h/24h/4d) and Down/Up totals
- Associated Users: DataTable with Nickname + Last Seen
- Switch Port: SectionHeader + toggle button + ConfigBlock showing port status

## Changes

### 1. Header Row

**Left:** IP address title (unchanged).

**Right:** Three action buttons with visual hierarchy:
- **Grant/Revoke Access** — filled danger/success button. Retains existing `ConfirmModal`.
- **Enable/Disable Rate Limit** — ghost button (`border: 1px solid var(--color-border)`, transparent background). Posts to existing `admin.ips.limit` route.
- **Enable/Disable DNS Filter** — ghost button. Posts to new `admin.ips.dns-filter` route.

Button labels describe the action (what clicking does). MetadataStrip shows current state.

Rate Limit and DNS Filter toggles do NOT require ConfirmModal — they are low-risk, reversible actions.

### 2. MetadataStrip

Expanded from 2 to 7 items:

| Item | Source | Display |
|------|--------|---------|
| Status | `ip.internet_enabled` + user denial check | "Allowed" (success) / "Denied" (danger) / "—" |
| MAC Address | `ip.mac` accessor | Monospace, or "—" if null |
| Rate Limiting | `ip.rate_limit_enabled` | "Enabled" (success) / "—" |
| DNS Filtering | `ip.dns_filtering_enabled` | "Enabled" (success) / "—" |
| Switch | `switchInfo.switchId` | Link to `admin.switches.show` or "—" if no port |
| Port | `switchInfo.portId` | Link to `admin.switches.ports.show` or "—" if no port |
| Comment | `ip.comment` | Text or "—" |

### 3. Two-Column Layout

Replace the current stacked full-width sections with a `grid-template-columns: 1fr 1fr` grid with `gap: 24px`.

- **Left column:** Associated Users — existing `DataTable` component with Nickname + Last Seen columns. Existing click-through to user show page. Existing empty state.
- **Right column:** Bandwidth — existing `TimeSeriesChart` with range selector and Down/Up totals. Existing loading/error states.

Responsive: collapses to single column at `<=1024px` (consistent with existing responsive breakpoints).

### 4. Switch Port Section — Removed

The entire Switch Port section is removed:
- No more `SectionHeader` for "Switch Port"
- No more port toggle button (Disable/Enable Port)
- No more `ConfigBlock` showing port status text
- No more `togglePort()` function

Switch and port information is now accessible via links in the MetadataStrip.

### 5. Backend Changes

#### New Route: DNS Filter Toggle

```
POST admin/ips/{ip}/dns-filter
```

New method `dnsFilter()` on `IpAddressController`:
- Validates `filter` as required boolean
- Sets `$ip->dns_filtering_enabled` to the boolean value
- Saves and redirects back with success message
- Pattern matches existing `limit()` and `internet()` methods

#### Controller Show Method Updates

The `show()` method must pass switch/port link data to the frontend:

```php
$switchInfo = null;
if ($port !== null) {
    $switchConfig = $this->resolveSwitchConfig($port);
    $switchInfo = [
        'switchId' => $switchConfig->id,
        'switchName' => $switchConfig->hostname,
        'portId' => $port->interface,
    ];
}
```

Pass `switchInfo` as a new prop to the Inertia render call.

Remove: `$status` and `$shutdown` variables and props — no longer needed.

#### Removed from Controller

- `port()` method — no longer called from this page (port toggle still accessible from the switch port detail page)
- `togglePort` route can remain registered for the switch port page; just not used here

**Note:** Do NOT remove the `port()` method or its route. It may be used by other pages.

### 6. Frontend Props Changes

```javascript
defineProps({
    ip: { type: Object, default: () => ({}) },
    port: { type: Object, default: () => ({}) },      // kept for metadata
    switchInfo: { type: Object, default: null },        // NEW
    users: { type: Array, default: () => [] },
});
// Removed: status (String), shutdown (Boolean)
```

## Files Modified

- `app/Http/Controllers/Admin/IpAddressController.php` — update `show()`, add `dnsFilter()`
- `routes/web.php` — add `admin.ips.dns-filter` route
- `resources/js/Pages/Admin/Ips/Show.vue` — full template/script rewrite

## Files NOT Modified

- `app/Http/Controllers/Admin/IpAddressController.php` `port()` method — kept, used elsewhere
- All existing components (MetadataStrip, DataTable, TimeSeriesChart, ConfirmModal) — used as-is

## Out of Scope

- Real-time bandwidth (WebSocket) — future enhancement
- Port toggle from IP page — available on switch port detail page
- IP address Index page changes — separate task
