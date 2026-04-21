# Dashboard Content & Layout Editor

## Purpose

Admins need full control over the portal dashboard — what blocks appear, their content, and how they're arranged. Currently the admin content page is read-only and the dashboard layout is hardcoded in Vue. This feature adds:

1. Admin CRUD for content blocks (create, edit, delete, toggle active)
2. A visual 2D grid editor for positioning and resizing blocks
3. A new `connection_strip` block type replacing the hardcoded connection strip
4. A `dns_filter` block replacing the pihole-specific toggle
5. A `UserParameter` model for arbitrary per-user key-value data
6. Content templating in text blocks using `{user.<key>}`, `{ip}`, `{mac}` placeholders

## Data Model

### ContentBlock Changes

Add grid positioning columns and drop `sort_order`:

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `grid_col` | integer | 1 | Starting column (1-3) |
| `grid_row` | integer | 1 | Starting row (1+, dynamic) |
| `col_span` | integer | 1 | Width in columns (1-3) |
| `row_span` | integer | 1 | Height in rows (1+) |

Drop `sort_order` — grid coordinates fully determine layout ordering.

The `active` scope changes from `orderBy('sort_order')` to `orderBy('grid_row')->orderBy('grid_col')`.

### Migration for Existing Data

The migration must:
1. Add the four grid columns with defaults
2. Assign a sensible default grid layout to existing blocks (sequential placement, 1×1 each)
3. Rename any `pihole_toggle` type blocks to `dns_filter`
4. Drop the `sort_order` column

### Block Types

| Type | Singleton | Editable Fields | Depends on Capability |
|------|-----------|----------------|-----------------------|
| `event_info` | No | Title + content | None |
| `custom_markdown` | No | Title + content | None |
| `connection_strip` | Yes | Title | None (uses IP/MAC data) |
| `bandwidth` | Yes | Title | None (uses IP stats) |
| `network_stats` | Yes | Title | None |
| `dns_filter` | Yes | Title + description | `dns-filtering` |

**Singleton enforcement:** The `ContentController@store` method checks if a block of the requested type already exists when the type is in a `SINGLETON_TYPES` constant array. Returns 422 if a duplicate is attempted. The admin editor UI hides singleton types from the "Add Block" picker when they already exist.

**`dns_filter`** replaces `pihole_toggle`. It references the `dns-filtering` capability via the existing `CapabilityAssignment` model. Title defaults to "DNS Ad Blocking", description defaults to "Toggle DNS filtering for your connection." — both editable.

**`connection_strip`** replaces the hardcoded connection strip from Dashboard.vue. Uses the existing `IpAddress->mac` accessor for MAC address. Shows IPv4, IPv6 (when available), MAC, and connection status.

### New Model: UserParameter

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-increment |
| `user_id` | bigint (FK) | References `users.id` |
| `key` | string | Parameter name (e.g., `seat`, `team`) |
| `value` | JSON | Cast to mixed/object. Stores arbitrary values. |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Unique constraint on `(user_id, key)`.

Managed via admin user edit page or populated by integrations.

## Admin Pages

### Content Blocks List (Enhanced)

`Admin/Content/Index.vue` — the existing page, enhanced:

- "Add Block" button opens a type picker (dropdown or modal). Singleton types hidden when they already exist.
- Delete action per block (with confirmation).
- Active/inactive toggle per block.
- Link to the Grid Editor page.
- Table continues to show title, type, grid position, and status.

### Grid Editor (New)

`Admin/Content/Editor.vue` — new page for visual layout editing.

**Routes:**
- `GET /admin/content/editor` → `ContentController@editor` → `admin.content.editor`
- `PUT /admin/content/layout` → `ContentController@updateLayout` → `admin.content.layout.update`

**Layout:**
- 3-column CSS Grid showing all active blocks positioned by their grid coordinates
- Each block shows a simplified preview:
  - Static blocks (event_info, custom_markdown): real content
  - Dynamic blocks (bandwidth, network_stats, connection_strip, dns_filter): simplified placeholder representation
- Block type label and size indicator (cols×rows) on each block
- Empty cells shown as dashed outlines with "Drop block here" hint

**Interactions:**
- **Drag** — grab anywhere on a block to move it. Ghost preview shows target position.
- **Resize** — bottom-right corner handle. Snaps to grid cells. Shows col×row indicator while dragging.
- **Click** — opens side panel for that block's settings (slides in from the right).
- **Add Block** — type picker, new block placed in first available cell.
- **Save Layout** — single batch request sends all block positions. Unsaved changes indicator.
- **Collision prevention** — blocks cannot overlap. Moving/resizing into occupied space is prevented.

**Side Panel** (opens on block click):
- Block type (read-only)
- Title (text input)
- Content (textarea/rich text — for text block types only)
- Size: column span selector (1, 2, or 3) and row span input (positive integer, minimum 1)
- Active toggle
- Save Block button
- Delete button (with confirmation)

**`updateLayout` endpoint:** Accepts an array of `{id, grid_col, grid_row, col_span, row_span}` objects. Validates bounds (col 1-3, row 1+, spans within grid, no overlaps). Batch-updates all blocks in a single transaction.

### Existing `reorder` Endpoint

The `POST /admin/content/reorder` route and `ContentController@reorder` method are replaced by `updateLayout`. Remove the old route and method.

## Dashboard Rendering

### Portal Dashboard Changes

`Dashboard.vue` changes significantly:

**Removed:**
- Hardcoded connection strip HTML
- Hero block extraction (`bandwidthBlock`, `piholeBlock` computeds)
- `heroTypes` array
- Separate hero row layout

**New:**
- Single CSS Grid driven entirely by block data
- Each block positioned via inline `grid-column` and `grid-row` styles computed from `grid_col`, `grid_row`, `col_span`, `row_span`
- All blocks pass through `BlockGrid`
- DNS detection warning stays outside the grid (settings-driven, always at top when active)

### BlockGrid Rewrite

`BlockGrid.vue` becomes a CSS Grid renderer:
- Reads grid coordinates from each block
- Sets `grid-template-columns: repeat(3, 1fr)` on desktop
- Each block gets `grid-column: {col} / span {colSpan}` and `grid-row: {row} / span {rowSpan}`
- Component registry updated: remove `dns_warning`, rename `pihole_toggle` → `dns_filter`, add `connection_strip`
- All blocks receive `blockContext` as a prop

### Responsive Behaviour

- **Desktop (xl+):** 3-column grid as configured
- **Tablet (md):** 2 columns — blocks wider than 2 cols clamp to 2
- **Mobile:** Single column — all blocks stack, ignoring grid position, ordered by `grid_row` then `grid_col`

### Block Context

DashboardController builds a `blockContext` object passed to every block via BlockGrid:

```php
'blockContext' => [
    'currentIp' => $ip->address,
    'ipAllowed' => (bool) $ip->allowed,
    'macAddress' => $ip->mac,
    'user' => $user->parameters->pluck('value', 'key'),
]
```

### Content Templating

Text blocks (event_info, custom_markdown) support placeholders in their content:
- `{user.<key>}` — replaced with the user's parameter value (e.g., `{user.seat}` → `A42`)
- `{ip}` — replaced with the user's current IP
- `{mac}` — replaced with the user's MAC address

Replacement happens on the frontend before rendering. Missing keys render as empty string.

## Component Changes

### New Components

- **`ConnectionStripBlock.vue`** — extracts the hardcoded connection strip markup from Dashboard.vue. Receives `blockContext` prop for IP, MAC, and status data.
- **`Admin/Content/Editor.vue`** — the grid editor page.
- **`Admin/Content/EditorSidePanel.vue`** — the slide-in editing panel.

### Renamed Components

- **`PiHoleToggleBlock.vue` → `DnsFilterBlock.vue`** — refactored to use the `dns-filtering` capability via `CapabilityAssignment` rather than being pihole-specific. Accepts editable title and description props.

### Modified Components

- **`BlockGrid.vue`** — rewrite from flat 2-column grid to CSS Grid renderer reading block coordinates. Component registry updated.
- **`Dashboard.vue`** — remove hardcoded layout, pass all blocks + blockContext to BlockGrid.
- **`Admin/Content/Index.vue`** — add CRUD actions (add, delete, toggle) and link to grid editor.

## Cleanup

- Remove `dns_warning` from BlockGrid's component registry (already settings-driven)
- Remove `PiHoleToggleBlock.vue` after creating `DnsFilterBlock.vue`
- Remove `ContentController@reorder` and its route
- Drop `sort_order` from content_blocks table
- Update `ContentBlockSeeder` with new types and default grid coordinates

## Testing

### PHP (PHPUnit)

**ContentBlock model:**
- Grid coordinate defaults (col 1, row 1, span 1×1)
- Active scope ordering by `grid_row` then `grid_col`

**ContentController:**
- CRUD operations (store, update, destroy)
- Singleton enforcement — reject duplicate `bandwidth`, allow duplicate `custom_markdown`
- `updateLayout` batch endpoint validates col 1-3, row 1+, span bounds, no overlaps
- Layout update executes in a single transaction
- Store assigns first available grid position to new block

**DashboardController:**
- `blockContext` includes `currentIp`, `ipAllowed`, `macAddress`, user parameters
- Blocks include grid coordinates in response

**UserParameter model:**
- CRUD operations
- Unique constraint on `(user_id, key)`
- Value casting to mixed/object

**Migration:**
- Existing `pihole_toggle` blocks renamed to `dns_filter`
- Default grid coordinates assigned to existing blocks
- `sort_order` column dropped

### JavaScript (Vitest)

**BlockGrid.vue:**
- Renders CSS Grid with correct `grid-column`/`grid-row` from block data
- Responsive collapse (3→2→1 columns)
- Handles empty grid
- Passes blockContext to all block components

**Grid Editor (Admin/Content/Editor.vue):**
- Renders blocks in grid positions
- Side panel opens on block click
- Add block type picker respects singleton rules
- Save sends batch layout update
- Collision detection prevents overlaps

**DnsFilterBlock.vue:**
- Renders title and description from props
- Toggle calls capability endpoint

**ConnectionStripBlock.vue:**
- Renders IP, MAC, status from blockContext
- Handles missing MAC gracefully

**Content templating:**
- `{user.seat}` replaced in text blocks with value from blockContext
- Missing keys render as empty string
- `{ip}` and `{mac}` placeholders work

### Playwright E2E

- Admin creates a content block via the list page
- Admin opens grid editor, drags a block, resizes it, saves layout
- Admin clicks a block, edits title in side panel, saves
- Dashboard renders blocks in correct grid positions
- Content templating shows user-specific values
