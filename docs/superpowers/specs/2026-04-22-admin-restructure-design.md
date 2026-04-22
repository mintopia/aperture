# Admin Navigation Restructure, Settings Consolidation & Content Pages

**Date:** 2026-04-22
**Status:** Approved

## Overview

Restructure the admin navigation into three logical groups (Management, Services, Content), remove the Stats feature, consolidate settings into a single page, and add a new Content Pages feature with a visual markdown editor.

## Navigation Structure

### New Sidebar Groups

**Management**
- Dashboard (`/admin`)
- Users (`/admin/users`)
- IP Addresses (`/admin/ips`)
- Switches (`/admin/switches`)
- DHCP (`/admin/dhcp`)

**Services**
- Integrations (`/admin/settings/integrations`)
- IPv6 Detection (`/admin/settings/ipv6-detection`)
- DNS Detection (`/admin/settings/dns-detection`)

**Content**
- Dashboard (`/admin/content`)
- Pages (`/admin/content/pages`) — NEW
- Theme (`/admin/settings/theme`)
- Settings (`/admin/content/settings`) — NEW consolidated

### Removed

- **Stats** — entire feature removed (controller, routes, Vue pages, tests)
- **Overview** sidebar group — Dashboard moves into Management
- **Tools** sidebar group — dissolved, items redistributed
- **Event Settings** page — fields dropped (unused)
- **Portal Settings** page — fields dropped (unused)
- **Settings sub-navigation** (`SettingsNav.vue`) — removed; Services items promoted to sidebar, Theme/Settings moved under Content group

## Settings Page (Consolidated)

**Route:** `GET /admin/content/settings`, `PUT /admin/content/settings`
**Controller:** `GeneralSettingsController` (new, replaces EventSettingsController + PortalSettingsController)

### Sections

**Branding**
- Site Title (string, max 255) — moved from ThemeSettingsController. Displayed in portal header and browser tab. Default: "Aperture".

**Network Defaults**
- DNS Filtering Default (boolean toggle) — whether new connections have DNS ad-blocking active by default.

**Legal**
- Terms and Conditions (composite field: source type selector [Content Page | Custom URL] + value). When "Content Page", shows a dropdown of existing content pages. When "Custom URL", shows a URL text input.
- Privacy Policy (same composite field pattern as above).

### Data Storage

Uses the existing `Setting` model (key-value store):
- `site_title` (string)
- `dns_filtering_default` (boolean)
- `terms_type` (enum: `page`, `url`)
- `terms_value` (string — page slug or URL)
- `privacy_type` (enum: `page`, `url`)
- `privacy_value` (string — page slug or URL)

### Migration

- Remove `site_title` from ThemeSettingsController (it stays in the `settings` table, just served from a different controller now).
- Delete EventSettingsController, PortalSettingsController, and their routes/views/tests.

## Content Pages Feature

### Model: `Page`

| Column | Type | Constraints |
|--------|------|-------------|
| id | bigint | PK, auto-increment |
| title | string(255) | required |
| slug | string(255) | required, unique, alpha-dash |
| content | text | nullable (markdown) |
| created_at | timestamp | |
| updated_at | timestamp | |

### Routes

**Admin (requires `can:admin`):**
- `GET /admin/content/pages` — list all pages
- `GET /admin/content/pages/create` — create form
- `GET /admin/content/pages/{page}/edit` — edit form
- `POST /admin/content/pages` — store
- `PUT /admin/content/pages/{page}` — update
- `DELETE /admin/content/pages/{page}` — destroy (with confirmation modal)

**Public (no auth required):**
- `GET /content/{slug}` — renders the page using the portal layout (header + user menu if logged in, just header if not). Uses the `prose` class for rendered markdown with accent-colored links.

### Admin Pages List

- Table with columns: Title, Slug (monospace, accent-colored), Updated (relative time with full date on hover)
- Clickable rows navigate to edit
- "+ New Page" button top-right
- Empty state: "No pages yet — Create pages for terms, privacy policies, or any information your visitors need."

### Page Editor

- Title field (text input)
- Slug field (text input with prepended `/content/` prefix, auto-generated from title on create, editable)
- Content field: visual markdown editor with Visual/Source toggle
- Actions: Save Page (primary), Cancel (secondary), Delete Page (danger, right-aligned, with confirmation modal)

### Markdown Editor Component

**Reusable component** (`MarkdownEditor.vue`) used in:
1. Content Pages editor (new)
2. Dashboard layout editor's EditorSidePanel (replaces current textarea for `custom_markdown` blocks)

**Features:**
- Visual/Source toggle — Visual mode is a WYSIWYG editor, Source mode shows raw markdown
- Toolbar: Bold, Italic, Underline, Strikethrough | H1, H2, H3 | Bullet List, Numbered List, Blockquote | Link, Code, Horizontal Rule
- Uses Tiptap (ProseMirror-based) for the visual editor with markdown serialization via `@tiptap/core`, `@tiptap/starter-kit`, and `@tiptap/vue-3`
- Renders with the app's typography (Bricolage Grotesque headings, Hanken Grotesk body)
- Dark-themed to match the admin panel

## Portal Header Changes

### AppLogo Component

- Remove the SVG aperture icon entirely
- Display only the site title text (from `appName` page prop)
- The `appName` prop should be populated from the `site_title` setting (with "Aperture" as fallback)

### Middleware/Provider

- The `site_title` setting must be shared with all Inertia responses as `appName` so it's available in the portal header and browser tab.

## Deletions

### Files to Remove
- `app/Http/Controllers/Admin/StatsController.php`
- `app/Http/Controllers/Admin/EventSettingsController.php`
- `app/Http/Controllers/Admin/PortalSettingsController.php`
- `resources/js/Pages/Admin/Stats/Index.vue`
- `resources/js/Pages/Admin/Stats/Bandwidth.vue`
- `resources/js/Pages/Admin/Settings/Event.vue`
- `resources/js/Pages/Admin/Settings/Portal.vue`
- `resources/js/Components/Admin/SettingsNav.vue`
- `tests/Feature/Admin/StatsControllerTest.php`
- `tests/Feature/Admin/EventSettingsControllerTest.php`
- `tests/Feature/Admin/PortalSettingsControllerTest.php`

### Routes to Remove
- `/admin/stats*`
- `/admin/settings/event`
- `/admin/settings/portal`

## Testing Requirements

### PHP (PHPUnit)
- GeneralSettingsController: CRUD for all settings fields, validation, page selector logic
- PageController: CRUD operations, slug uniqueness, markdown rendering
- Public page route: accessible without auth, 404 for missing slugs, correct layout
- Navigation: verify sidebar structure matches spec

### JavaScript (Vitest)
- MarkdownEditor component: visual/source toggle, toolbar actions, markdown serialization
- Settings page: form submission, page selector behavior
- Pages list: rendering, empty state, clickable rows
- AppLogo: renders site title without icon

### E2E (Playwright)
- Full page CRUD flow
- Settings save flow with page selector
- Public page viewing while logged out
- Navigation structure verification

## Non-Goals

- Draft/publish workflow for pages (may add later)
- Page versioning
- Rich media in pages (images, embeds) — markdown text only for now
- Search/filter on pages list (unlikely to have many pages)
