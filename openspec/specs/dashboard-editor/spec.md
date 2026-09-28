# dashboard-editor Specification

## Purpose
Lets admins control the portal dashboard: which Content Blocks appear, their content and settings, and where each sits on a three-column grid. The dashboard renders exactly the layout the admin saves, with per-user template values filled in.

## Requirements

### Requirement: Block Management
Admins SHALL be able to create, edit, activate or deactivate, and delete Content Blocks from the Dashboard Editor. Deleting a block MUST require confirmation. Only active blocks SHALL appear on the portal dashboard.

#### Scenario: Add a block
- **WHEN** an admin adds a block of a chosen type
- **THEN** the block is created and placed in the first free grid position

#### Scenario: Deactivate a block
- **WHEN** an admin deactivates a block
- **THEN** it remains in the editor but is not shown on the portal dashboard

### Requirement: Block Types and Singletons
The system SHALL offer the block types `custom_markdown`, `connection_strip`, `bandwidth` and `dns_filter`. The types `connection_strip`, `bandwidth` and `dns_filter` MUST be singletons: at most one block of each type SHALL exist.

#### Scenario: Duplicate singleton rejected
- **WHEN** an admin creates a `bandwidth` block while one already exists
- **THEN** the request is rejected with a validation error

#### Scenario: Picker hides existing singletons
- **WHEN** an admin opens the add-block picker and a `dns_filter` block exists
- **THEN** `dns_filter` is not offered

#### Scenario: Repeatable type
- **WHEN** an admin creates a second `custom_markdown` block
- **THEN** it is accepted

### Requirement: Grid Layout Model
Each Content Block SHALL have a starting column (1-3), a starting row (1 or greater), a column span (1-3) and a row span (1 or greater). Grid coordinates SHALL fully determine display order; there is no separate sort order. A block MUST NOT extend past column 3.

#### Scenario: Default size
- **WHEN** a block is created
- **THEN** it spans one column and one row

### Requirement: Layout Saving
The system SHALL save the layout of all blocks in a single batch request applied atomically. The request MUST be rejected, changing nothing, if any coordinate or span is out of bounds or if any two blocks overlap.

#### Scenario: Overlapping layout
- **WHEN** a submitted layout places two blocks on the same cell
- **THEN** it is rejected with a validation error and no block moves

#### Scenario: Overflowing block
- **WHEN** a block's start column plus span exceeds the third column
- **THEN** the layout is rejected

### Requirement: Drag and Reflow
The editor SHALL let admins move a block by dragging it on the grid. When the drop position overlaps other blocks, those blocks SHALL be pushed down, cascading to any block they in turn overlap, and the result SHALL be previewed before the drop. Cancelling a drag MUST restore all blocks to their original positions.

#### Scenario: Drop onto occupied cells
- **WHEN** an admin drags a block over another block and drops it
- **THEN** the underlying blocks shift down to make room and the layout is saved

#### Scenario: Cancel drag
- **WHEN** an admin presses Escape or leaves the grid mid-drag
- **THEN** every block returns to its original position

### Requirement: Resize Handles
Each block in the editor SHALL have a bottom-right drag handle that resizes it in whole grid cells, with a minimum of 1x1 and a maximum width of three columns. Resizing into other blocks SHALL push them down as with dragging. Block size MUST NOT be editable through the side panel.

#### Scenario: Resize into a neighbour
- **WHEN** an admin drags the handle so the block would overlap another block
- **THEN** the neighbour is pushed down and no overlap remains

### Requirement: Block Settings Panel
Selecting a block in the editor SHALL open a side panel showing its type (read-only), title, active state, and controls specific to the block type, with save and delete actions.

#### Scenario: Edit a title
- **WHEN** an admin selects a block, changes its title and saves
- **THEN** the change persists and the dashboard shows the new title

### Requirement: Markdown Content
`custom_markdown` blocks SHALL render their content as Markdown with template variables resolved first, and the resulting HTML MUST be sanitized before display.

#### Scenario: Script in content
- **WHEN** a markdown block's content contains a script tag
- **THEN** it is stripped and not executed on the dashboard

### Requirement: Template Variables
Text content and Connection Strip field values SHALL support the placeholders `{ipv4}`, `{ipv6}`, `{mac}`, `{user.<property>}` and `{user.params.<key>}`. `{ipv6}` SHALL be the IPv6 address associated with the client's current MAC address. Unknown or missing keys MUST render as an empty string. The editor SHALL show a collapsible reference of available variables beneath template-capable inputs, where choosing a variable inserts it at the cursor of the last focused input or copies it if none is focused.

#### Scenario: User parameter
- **WHEN** a user has a parameter `seat` with value `A42` and content contains `{user.params.seat}`
- **THEN** the dashboard shows `A42` for that user

#### Scenario: Missing key
- **WHEN** content references a parameter the user does not have
- **THEN** the placeholder renders as an empty string

### Requirement: Connection Strip Fields
The `connection_strip` block SHALL display a configurable list of label and value-template fields, editable in the side panel (add, remove, edit). When no fields are configured it SHALL display IPv4, IPv6, MAC Address and Status.

#### Scenario: Custom field
- **WHEN** an admin adds a field labelled Seat with value `{user.params.seat}`
- **THEN** the dashboard shows Seat with the user's seat value

#### Scenario: No fields configured
- **WHEN** a `connection_strip` block has no configured fields
- **THEN** the four default fields are shown

### Requirement: DNS Filter Block
The `dns_filter` block SHALL let the user toggle DNS filtering for their connection through the DNS filtering Capability, with an admin-editable title and description. When these are unset, the block's title and content SHALL be used instead.

#### Scenario: User toggles filtering
- **WHEN** a user switches the DNS filter block on or off
- **THEN** the change is applied through the Integration assigned the DNS filtering Capability

### Requirement: Dashboard Rendering
The portal dashboard SHALL render all active blocks on a three-column grid at their saved positions. On medium screens it SHALL use two columns, clamping wider blocks to two; on small screens it SHALL stack blocks in one column ordered by row then column.

#### Scenario: Mobile
- **WHEN** the dashboard is viewed on a small screen
- **THEN** blocks appear in a single column ordered by row, then column
