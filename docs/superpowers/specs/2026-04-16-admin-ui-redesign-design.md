# Admin UI Redesign — Design Specification

**Date**: 2026-04-16
**Status**: Approved

## Overview

Complete overhaul of the Aperture admin UI to match the v5 mockups. The admin panel uses Vue 3 + Inertia.js + Tailwind CSS with CSS custom properties for theming. This spec covers all admin pages: Settings (integration config), Dashboard, User/IP/Port detail pages, Content Management, and Theme system fixes.

---

## 1. Settings Architecture

### 1.1 Integration Table Page (`/admin/settings/integrations`)

**Navigation Sidebar** — grouped sections:
- **INTEGRATIONS**: Services, Switches
- **FEATURES**: Auto-Allow, IPv6 Detection, DNS Warning
- **APPEARANCE**: Theme
- **GENERAL**: Event, Portal

**Main Content** — table with columns:
- **Service** (text name, no icons): Borealis, OPNsense, LibreNMS, ntopng, Pi-hole
- **Status**: Enabled/Disabled pill (read-only on table)
- **Health**: Colored dot (green/yellow/red/grey) showing last known status from manual test
- **Capabilities**: Tag pills for each capability the integration supports. Tags are greyed out if this integration is NOT the active provider for that capability.

Rows are read-only. Clicking a row navigates to the integration's config page.

### 1.2 Integration Config Page (`/admin/settings/integrations/{service}`)

**Header**: Back link, service name, description, connection status badge, capability tags.

**Capability Assignment Section**: For each capability the integration supports:
- Shows capability name with a toggle "Use [Service] for [capability]"
- When toggled on, this integration becomes the active provider for that capability
- If another integration was previously the active provider, it is automatically deactivated for that capability
- Example: PiHole supports `dhcp`, `dns-filtering`, `ip-to-mac`. If DHCP is assigned to OPNsense, the `dhcp` toggle on PiHole is off and its tag appears greyed on the table.

**Configuration Form**: Grouped by section (e.g., Connection, Captive Portal, Rate Limiting for OPNsense). Fields match the current IntegrationConfig model.

**Actions**: Test Connection button, Save button.

**Connection History**: Log of past test results showing timestamp, success/failure, error message if any.

### 1.3 Integration Definitions

| Service | Capabilities | Config Fields |
|---------|-------------|---------------|
| Borealis | authentication, sso, user-info | provider_type, client_id, client_secret, authorize_url, token_url, userinfo_url |
| OPNsense | captive-portal, firewall, rate-limiting, dhcp | endpoint, key, secret, captive_portal_id, verify_ssl, zone_id, ratelimit_up_uuid, ratelimit_down_uuid, dhcp.enabled, dhcp.pool_size |
| LibreNMS | ip-to-mac, mac-to-port, port-bandwidth, device-list | endpoint, api_key, enabled |
| ntopng | user-bandwidth, top-talkers, aggregate-stats | endpoint, username, password, interface, enabled |
| Pi-hole | dns-filtering, dhcp, ip-to-mac | endpoint, password, noblock_group_id, enabled, verify_ssl |

### 1.4 Capability Assignment Model

New database table `capability_assignments`:
- `capability` (string, unique) — e.g., "dhcp", "ip-to-mac"
- `integration` (string) — e.g., "opnsense", "pihole"

When rendering the integrations table, each integration's capability tags are compared against this table. If the integration is the assigned provider, the tag renders fully colored. If not, it renders greyed out.

### 1.5 Switches Page (`/admin/settings/switches`)

Stays similar to current but with the new sidebar nav. Table of configured switches with Add/Edit/Delete functionality.

### 1.6 Feature Settings Pages

- **Auto-Allow** (`/admin/settings/auto-allow`): OUI prefix list, scan interval, enabled toggle
- **IPv6 Detection** (`/admin/settings/ipv6`): Enabled toggle, detection endpoint
- **DNS Warning** (`/admin/settings/dns`): Expected server, probe domain

### 1.7 Theme, Event, Portal Pages

Remain as separate pages under their sidebar sections. Theme page gets bug fixes (see Section 6).

---

## 2. Dashboard

### 2.1 Stat Cards Row

4 cards in a responsive grid:
1. **Online Now** (hero card, green accent): Large number, "of {total}" subtitle, percentage
2. **Total Users**: Simple count
3. **IPs Active**: Count of allowed IPs
4. **Blocked**: Count of blocked users/IPs, red accent

### 2.2 DHCP Pools + Port Errors Row

Side-by-side panels:

**DHCP Pools** (wider panel):
- One row per pool from the DHCP service
- Each row: Pool name, progress bar (colored by utilisation), used/total count
- Progress bar colors: blue (<60%), yellow (60-85%), red (>85%)

**Port Errors** (narrower panel):
- Top ports by error count (from NetworkSwitchInterface)
- Each row: Port link, error type badge (CRC, Input, Collision, Output)

### 2.3 Top Bandwidth & Recent Users Table

Paginated table:
- Columns: Nickname (link), Email, IPs count, Bandwidth (formatted), Status pill, Last Seen
- Default sort by bandwidth descending
- Standard pagination (Showing X-Y of Z, page numbers)

### 2.4 Portal Reset

**Removed from UI entirely.** Available only via artisan command: `php artisan portal:reset`

---

## 3. User Detail Page

**URL**: `/admin/users/{id}`

**Header Row**:
- Breadcrumb: Admin / Users / {Nickname}
- Actions: Edit Roles button, Block/Unblock User button

**Metadata Strip**:
- Nickname, Email, Roles (comma-separated pills), Status (Active/Blocked pill), Auth type (e.g., "SSO (OAuth2)"), First Seen (formatted date)

**Bandwidth Total Panel**:
- Full-width card showing download/upload totals (formatted bytes)
- Line chart: Download (blue) and Upload (pink/red dashed) over time
- Data from TrafficMonitorInterface.getUserBandwidth()

**IP Addresses Table**:
- Columns: Address (link), Type (IPv4/IPv6), MAC (mono), Port (link), Status pill, Last Seen
- Links: Address → IP detail, Port → Port detail

---

## 4. IP Address Detail Page

**URL**: `/admin/ips/{id}`

**Header Row**:
- Breadcrumb: Admin / IPs / {Address}
- Actions: Rate Limit button, Revoke/Grant button

**Metadata Strip**:
- Address (mono), Type (IPv4/IPv6), MAC (mono), User (link), Port (link), Status pill, Last Seen

**Split Panels Row**:
- **Related IPv6**: Shows paired IPv6 address if available (address, MAC, status)
- **Bandwidth — Last 24 Hours**: Download/upload totals + line chart

**Activity Log Table**:
- Columns: Time, Event (pill with color), Details
- Event types: Allowed, Rate Limited, Detected, Blocked
- Filter dropdown: "All Events" / specific event types
- "Show all activity →" link at bottom

---

## 5. Port Detail Page

**URL**: `/admin/ports/{portId}`

**Header Row**:
- Breadcrumb: Admin / Ports / {InterfaceId}
- Action: Refresh button

**Metadata Strip**:
- Interface, Description, Status (Up/Up pill), Speed, VLAN, PoE

**Middle Row (split)**:
- **Bandwidth — Last 24 Hours**: In/Out totals + line chart (Inbound blue, Outbound pink/red)
- **Connected Devices**: Cards showing user name (link), IP, MAC, status pill

**Bottom Row (split)**:
- **Interface Errors (24H)**: 2x2 grid (Input, CRC, Output, Collisions) with counts. Status bar at bottom ("Clean — no errors" or error level)
- **Running Config**: Code block with syntax-highlighted IOS config from the switch

---

## 6. Theme System Fixes

### Bugs to Fix:
1. **Validation**: Add `default` to allowed theme names in `updateTheme()` validation rule
2. **Amber Glow CSS**: Verify `resources/css/themes/amber-glow.css` exists. If not, create it with warm orange/amber tones.
3. **Live Preview**: When user clicks a theme swatch in the Theme settings page, apply it immediately (preview) without saving. Only persist on Save.

### Theme Settings Page:
- Theme grid (current UI is close to mockup)
- Mode toggle (light/dark)
- Save button

---

## 7. Content Management

### 7.1 Content Blocks Table (`/admin/content`)

**Header**: "Content" with "+ New Block" button

**Table Columns**: Drag handle (⠿), Title (link), Type, Placement, Status pill (Live/Draft), Updated, Edit button

**Empty State**: Icon + "No content blocks yet" + description + "+ Create First Block" CTA button

### 7.2 Block Editor

Create/Edit form for content blocks:
- Title, Type (Rich Text, Alert, Links), Placement (Dashboard), Content (markdown editor), Status toggle (Live/Draft)

### 7.3 Dashboard Layout Editor

Full drag-and-drop grid editor:
- Canvas area with 12-column grid
- Blocks can be positioned and resized by dragging
- Block palette on the right showing available blocks
- Undo button, Grid size indicator
- Save layout button

**Layout Storage**: Each block's position stored as JSON in `content_blocks.layout`:
```json
{ "x": 0, "y": 0, "w": 6, "h": 2 }
```
Where x/y are grid coordinates and w/h are width/height in grid units. Layout saved as a batch update to all blocks' positions.

---

## 8. Backend Changes Required

### New/Modified Controllers:
- `SettingsController`: Split integration table vs individual config pages, add capability assignment endpoints
- `DashboardController`: Add DHCP pools, port errors, top bandwidth users data
- `UserController@show`: Add bandwidth data, auth type, first seen
- `IpController@show`: Add related IPv6, bandwidth, activity log
- `PortController@show`: Add bandwidth, connected devices, error grid, running config
- `ContentController`: Full CRUD for content blocks, layout save/load

### New Models/Tables:
- `capability_assignments` table (capability, integration)
- `connection_test_logs` table (integration, tested_at, success, error_message)

### API Endpoints:
- `PUT /admin/settings/integrations/{service}` — save integration config
- `POST /admin/settings/integrations/{service}/test` — test connection
- `PUT /admin/settings/capabilities` — update capability assignments
- `GET /admin/settings/integrations/{service}/health-log` — connection history

---

## 9. UI Components Needed

### New Components:
- `CapabilityTag.vue` — colored pill with active/inactive state
- `BandwidthChart.vue` — reusable line chart for download/upload (shared across User, IP, Port detail pages)
- `ActivityLog.vue` — filterable event log table
- `ConnectedDeviceCard.vue` — card showing user/IP/MAC for port detail
- `InterfaceErrorGrid.vue` — 2x2 grid for port error counters
- `DhcpPoolBar.vue` — progress bar for DHCP pool utilisation
- `PortErrorRow.vue` — port name + error badge for dashboard
- `LayoutEditor.vue` — drag-and-drop grid editor for content blocks
- `MarkdownEditor.vue` — markdown content editor for blocks

### Modified Components:
- `SettingsNav.vue` — new grouped sidebar structure
- `StatCard.vue` — hero variant with percentage/subtitle
- `MetadataStrip.vue` — support for link items, pill items, mono formatting
- `DataTable.vue` — support for drag handles, clickable rows with hover

---

## 10. Testing Strategy

- PHPUnit feature tests for all new controller actions
- Unit tests for capability assignment logic
- Vue component tests (Vitest) for new components
- Playwright E2E tests for settings flow, dashboard rendering, detail page navigation
- All interactive elements must have `data-testid` attributes

---

## 11. Out of Scope

- Portal Reset UI (moved to artisan command only)
- User Portal pages (separate spec)
- Captive Portal pages (separate spec)
- Real-time WebSocket updates for dashboard stats
- Custom theme color picker (deferred — only bundled theme selection for now)
