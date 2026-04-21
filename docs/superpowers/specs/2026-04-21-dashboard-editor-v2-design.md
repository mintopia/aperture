# Dashboard Editor V2 — Design Spec

## Overview

Ten changes to the Dashboard Content & Layout Editor: removing unused block types, fixing markdown rendering, adding settings-based block configuration, a template variable reference panel, and grid drag-reflow with resize handles.

All changes ship as a single unified spec. Implementation is phased: removals first, then content/settings, then grid interactions.

## 1. Block Type Removals & Route Consolidation

### Block type removal

Delete three block types that are no longer needed: `event_info`, `network_stats`, `connection_status`.

**Backend:**

- `ContentBlock::SINGLETON_TYPES` becomes `['connection_strip', 'bandwidth', 'dns_filter']`
- Migration: `DELETE FROM content_blocks WHERE type IN ('event_info', 'network_stats', 'connection_status')`
- Update `ContentBlockSeeder` to only seed remaining types
- Update `ContentBlockFactory` if it references removed types

**Frontend:**

- Delete `resources/js/Components/Blocks/EventInfoBlock.vue`
- Delete `resources/js/Components/Blocks/NetworkStatsBlock.vue`
- Delete `resources/js/Components/Blocks/ConnectionStatusBlock.vue`
- Remove deleted types from the component registry in `BlockGrid.vue`

### Route consolidation

The admin content table view (`Admin/Content/Index.vue`) serves no purpose with the grid editor as the primary interface.

- Delete `resources/js/Pages/Admin/Content/Index.vue`
- `ContentController::index()` renders `Admin/Content/Editor` directly (absorbs the current `editor()` method logic)
- Remove `ContentController::editor()` method
- Update route definitions: `admin.content.index` points to the editor, remove the `admin.content.editor` route
- Update breadcrumbs: editor is top-level "Content" with no parent link
- Update any navigation links that referenced the old index or editor routes

### Remaining block types

`custom_markdown`, `connection_strip`, `bandwidth`, `dns_filter`

## 2. Markdown Rendering

`CustomMarkdownBlock.vue` currently renders content as plain text with whitespace preservation. It needs to render actual markdown.

**Dependencies:** `marked`, `dompurify` (npm)

**Render chain:**

1. Template variables resolved via `renderTemplate(content, context)`
2. Markdown parsed via `marked.parse(resolvedContent)`
3. HTML sanitized via `DOMPurify.sanitize(html)`
4. Rendered via `v-html`

**Styling:** Scoped prose styles for the markdown output (headings, lists, links, code blocks). Use existing Tailwind typography plugin if available, otherwise add scoped styles.

## 3. Settings-Based Block Configuration

Both connection strip and DNS filter blocks store their configuration in the `settings` JSON column on `ContentBlock`, edited through type-specific UI in the side panel.

### Connection Strip

**Current state:** Hardcoded four fields — IPv4, IPv6, MAC, Status.

**`settings.fields` schema:**

```json
[
  { "label": "IPv4", "value": "{ipv4}" },
  { "label": "IPv6", "value": "{ipv6}" },
  { "label": "MAC", "value": "{mac}" },
  { "label": "Seat", "value": "{user.params.seat}" }
]
```

- If `settings.fields` is empty/null, fall back to the current four hardcoded fields as defaults. No data migration needed — existing blocks just work.
- Each field's `value` runs through `renderTemplate()` at render time.

**Side panel UI** (when a `connection_strip` block is selected):

- Repeatable field list: each row has a text input for label, a text input for value template, and a trash icon to remove
- "+" button below the list to add a new row
- Drag handle per row for reordering (nice-to-have, not required for v1)

### DNS Filter

**Current state:** Title and content come from the block's `title` and `content` columns.

**Move to settings:**

- `settings.title` — editable title
- `settings.description` — editable description
- Side panel shows two text inputs when a `dns_filter` block is selected
- If `settings.title`/`settings.description` are empty, fall back to the `title`/`content` column values for graceful migration

### Side Panel Changes

`EditorSidePanel.vue` already shows controls based on the selected block type. Add a type-specific settings section that detects the block type and renders the appropriate form. Settings are saved via the existing `update` endpoint which already accepts `settings`.

## 4. Template Key Reference

When editing blocks that support templating, a collapsible "Available Variables" section appears in the side panel below the relevant input fields.

### Blocks that show the reference

- `connection_strip` — below each field value input
- `custom_markdown` — below the content textarea

### Available variables

| Group | Variable | Description |
|-------|----------|-------------|
| Connection | `{ipv4}` | Client IPv4 address |
| Connection | `{ipv6}` | Client IPv6 address (resolved from DB via IPv4 lookup) |
| Connection | `{mac}` | Client MAC address |
| User | `{user.name}` | User's display name |
| User | `{user.*}` | Any direct property on the user object |
| User Parameters | `{user.params.*}` | User parameter by key (e.g. `{user.params.seat}`, `{user.params.vlan}`) |

### IPv6 resolution

`{ipv6}` is the IPv6 address corresponding to the client's current IPv4 address, resolved via a database lookup. The IPv6 mapping is populated from DHCP or from the separate IPv6 fetching check. The backend assembles the full template context (including resolved IPv6) before passing it to the frontend.

### `renderTemplate` updates

- Replace the single `{ip}` pattern with separate `{ipv4}` and `{ipv6}` patterns
- Add `{user.params.xyz}` pattern: resolves from the user's params collection
- Regex evaluation order:
  1. `{user.params.xyz}` — user parameters
  2. `{user.xyz}` — user object properties
  3. `{ipv4}`, `{ipv6}`, `{mac}` — connection context

### Variable source of truth

The variable list is defined as a constant array in `resources/js/utils/templateVariables.js`. Both `renderTemplate` and the reference panel import from this file. Adding a new variable means updating one place.

### UI behaviour

- Collapsible section, collapsed by default. Header: "Available Variables" with a chevron toggle.
- Each variable shown as a clickable chip: monospace key on the left, short description on the right.
- Clicking a chip inserts the key at the cursor position of the most recently focused input field above it.
- If no input is focused, clicking copies the key to clipboard with a brief tooltip confirmation.

## 5. Grid Interactions — Resize Handles & Drag Reflow

### Remove span controls from side panel

Remove `col_span` and `row_span` inputs from `EditorSidePanel.vue`. Resizing is exclusively via drag handles on the grid.

### Resize drag handles

Each block in the editor grid gets a single drag handle on the bottom-right corner.

- Dragging the handle resizes the block by snapping to grid cell boundaries
- Uses existing `resizeBlock()` from `useGridEditor.js` and `canPlace()` for validation
- Visual feedback: ghost outline shows the proposed new size while dragging
- Constraints: minimum 1x1, maximum 3 columns wide, cannot overlap other blocks
- If resizing would overlap neighbours, they push down (same reflow logic as drag)

### Push-down reflow

When dragging a block over occupied cells, blocks underneath shift down to make room.

**New function in `useGridEditor.js`:**

`computeDisplacement(draggedId, targetCol, targetRow, colSpan, rowSpan)` — returns a displacement map `{ blockId: newRow, ... }`:

1. Identifies which blocks would be overlapped at the target position
2. Calculates how many rows each needs to shift down
3. Cascades: if pushing block A down causes it to overlap block B, block B shifts too
4. Returns final displaced positions for all affected blocks

**Drag behaviour:**

- `dragover`: displacement is computed and applied as a preview. Blocks visually shift but positions are not committed.
- `drop`: displaced positions are committed and layout is saved via `updateLayout`.
- Cancel (drag leaves grid / Escape): all blocks snap back to original positions.

**State management:**

- Before any drag or resize starts, snapshot all current positions
- Preview state is applied reactively (Vue refs) so the grid animates smoothly with CSS transitions
- Cancel restores the snapshot
- Commit writes the final positions via the `updateLayout` endpoint

## Implementation Phases

The spec is implemented in this order to minimise intermediate breakage:

1. **Phase 1 — Removals:** Block type deletion, migration, route consolidation, component cleanup
2. **Phase 2 — Markdown:** Install dependencies, update `CustomMarkdownBlock.vue`
3. **Phase 3 — Settings & Templates:** Connection strip fields UI, DNS filter settings, template variable reference panel, `renderTemplate` updates
4. **Phase 4 — Grid Interactions:** Remove span controls from side panel, add resize handles, implement push-down reflow with `computeDisplacement`

## Files Affected

### Deleted

- `resources/js/Pages/Admin/Content/Index.vue`
- `resources/js/Components/Blocks/EventInfoBlock.vue`
- `resources/js/Components/Blocks/NetworkStatsBlock.vue`
- `resources/js/Components/Blocks/ConnectionStatusBlock.vue`

### Modified

- `app/Models/ContentBlock.php` — update `SINGLETON_TYPES`
- `app/Http/Controllers/Admin/ContentController.php` — merge index/editor, remove editor method
- `database/seeders/ContentBlockSeeder.php` — remove deleted types
- `database/factories/ContentBlockFactory.php` — remove deleted types if referenced
- `resources/js/Components/BlockGrid.vue` — remove deleted types from registry
- `resources/js/Components/Blocks/CustomMarkdownBlock.vue` — markdown rendering
- `resources/js/Components/Blocks/ConnectionStripBlock.vue` — settings-driven fields
- `resources/js/Components/Blocks/DnsFilterBlock.vue` — settings-driven title/description
- `resources/js/Components/Admin/Content/EditorSidePanel.vue` — type-specific settings forms, remove span controls
- `resources/js/Pages/Admin/Content/Editor.vue` — resize handles, reflow preview, route updates
- `resources/js/composables/useGridEditor.js` — `computeDisplacement`, reflow state management
- `resources/js/utils/contentTemplating.js` — new patterns for `{ipv4}`, `{ipv6}`, `{user.params.*}`
- `routes/web.php` — remove `content.editor` route, keep resource route and `content.layout.update`

### Created

- Migration: delete orphaned `content_blocks` rows
- `resources/js/utils/templateVariables.js` — shared variable definitions
