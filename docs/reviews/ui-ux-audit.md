# Aperture UI/UX Audit Report

**Date:** 2026-04-22
**Auditor:** Claude Opus 4.6 (automated)
**Scope:** Full frontend codebase review -- Vue 3 + Inertia.js + Tailwind CSS v4
**Codebase snapshot:** Branch `feature/improvements`, commit `7e034a4`

---

## Executive Summary

Aperture's frontend is a well-constructed admin tool with a strong design system foundation. The OKLCH-based theming is sophisticated and consistently applied, the component library is focused, and the overall visual language is clean and professional. However, there are meaningful gaps in accessibility, responsive design, and interaction patterns that would affect real users.

### Overall Scores

| Category                   | Score  | Grade |
|---------------------------|--------|-------|
| Visual Consistency        | 8.5/10 | A-    |
| Component Quality         | 7.5/10 | B+    |
| Accessibility             | 5.0/10 | C     |
| Responsive Design         | 4.5/10 | C-    |
| Design System Adherence   | 9.0/10 | A     |
| Information Architecture  | 7.5/10 | B+    |
| Interaction Design        | 6.5/10 | B-    |
| Typography & Hierarchy    | 8.5/10 | A-    |
| Anti-patterns             | 8.0/10 | A-    |

**Overall: 7.2/10 (B)**

### Top 5 Priority Issues

1. **P0 -- Global Search modal has no focus trap or ARIA roles** (`GlobalSearch.vue`). Screen reader users cannot navigate the search dialog, and keyboard focus can escape behind the backdrop.

2. **P1 -- Dashboard stat strip and MetadataStrip break on mobile** (`Dashboard.vue`, `MetadataStrip.vue`). Horizontal flex layouts with fixed `mr-8 pr-8` spacing overflow on narrow viewports with no wrapping or scroll fallback.

3. **P1 -- No skip-navigation link in either layout**. Keyboard and screen reader users must tab through the entire sidebar/header on every page load.

4. **P1 -- Switches/Index uses a hand-built table instead of DataTable** (`Switches/Index.vue`). This duplicates sorting logic, ARIA attributes, and row interaction patterns that already exist in the reusable component.

5. **P2 -- User confirmation dialogs use `window.confirm()`** (`Account/Settings.vue`). The passkey deletion and password clearing flows use browser-native `confirm()` instead of the existing `ConfirmModal` component, creating an inconsistent and un-themed experience.

---

## 1. Visual Consistency

### Strengths

- The warm-charcoal OKLCH palette is applied uniformly across every component and page. There are no raw hex colors in the component files (with one exception noted below).
- Typography uses a clear three-font system: Bricolage Grotesque for headings, Hanken Grotesk for body, JetBrains Mono for code/data. This is enforced via `@theme` tokens and applied consistently.
- Button styles follow a consistent pattern: `rounded-md border px-4 py-[7px] text-[13px] font-semibold` across primary, secondary, and destructive variants.
- The status-dot pattern (7px circle with box-shadow glow) is used consistently for enabled/disabled/sync status across Switches, IPs, and Integrations.

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P2 | `Pages/Auth/Login.vue:186` | Passkey error uses `text-red-500` instead of `text-[var(--color-danger)]`. This is the only hardcoded Tailwind color in the component layer. |
| P3 | `Pages/Admin/Ips/Create.vue:27` | Page title `h1` is missing `text-[var(--color-text)]` class that every other page title includes. On light mode this defaults correctly but is inconsistent. |
| P3 | `Pages/Account/Settings.vue` | Uses `rounded-xl` for card sections while the rest of the admin uses `rounded` or `rounded-md`. Also uses `rounded-lg` for buttons while admin pages use `rounded-md`. |
| P3 | `Pages/Auth/Login.vue` | Input fields use `rounded-lg` and `bg-[var(--color-bg)]` while admin forms use `rounded` and `bg-[var(--color-surface)]`. Acceptable for a standalone login page but inconsistent with the admin input convention. |
| P3 | `Components/UI/Pagination.vue` | Pagination links use `rounded-md border border-[var(--color-border-hover)]` while inactive links have `border-transparent`. This creates a visual jump when pagination state changes. |

---

## 2. Component Quality

### Strengths

- The UI component library is focused and well-scoped: DataTable, FilterBar, StatusPill, ConfirmModal, FormField, Pagination, StatCard, MetadataStrip, etc.
- Props are typed with validators where appropriate (StatusPill, ConfirmModal, AlertBanner).
- DataTable handles empty states, sorting, keyboard navigation (Enter/Space on rows), and ARIA sort attributes.
- ConfirmModal implements focus trapping, focus restoration, Escape key handling, and uses `Teleport` correctly.
- TimeSeriesChart gracefully handles loading, empty, and data states with appropriate visual treatment.

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P1 | `Pages/Admin/Switches/Index.vue` | Builds a complete table from scratch (418 lines) with its own sorting, row click handlers, ARIA attributes, and keyboard events instead of using DataTable. This duplicates ~100 lines of logic and makes the sorting/interaction patterns drift from the shared component. **Fix:** Refactor to use DataTable with sort props. |
| P2 | `Components/UI/FilterBar.vue` | The search input has no debounce. On the IPs page, `@keyup.enter="search"` is added at the page level, but on the Users page, filtering is immediate on every keystroke via `computed`. These two patterns should be unified. |
| P2 | `Components/UI/MarkdownEditor.vue:30-53` | The `markdownToHtml` and `htmlToMarkdown` converters use naive regex that will break on nested formatting, multi-line list items, code blocks, and many other Markdown constructs. This is a ticking time bomb for content authors. **Fix:** Use a proper Markdown parser like `marked` or `markdown-it`. |
| P2 | `Components/UI/MarkdownEditor.vue:104` | `insertLink()` uses `window.prompt()` for URL input. This is un-themed, blocks the thread, and is impossible to test in automated E2E. Should use an inline popover or the existing modal pattern. |
| P3 | `Components/UI/DataTable.vue` | Clickable rows use `role="link"` which is semantically incorrect -- rows are `<tr>` elements. Consider using `role="row"` (which is the default) and wrapping the navigation in a proper link or using `aria-description`. |
| P3 | `Components/UI/StatusPill.vue` | The `validator` limits to `['success', 'danger', 'warning', 'info', 'neutral']` but `Users/Show.vue` passes `'muted'` as a status, which would trigger a Vue warning in development. |

---

## 3. Accessibility

### Critical Issues

| Severity | File | Issue |
|----------|------|-------|
| P0 | `Components/Admin/GlobalSearch.vue` | The search overlay has no `role="dialog"`, no `aria-modal`, no `aria-labelledby`, and no focus trap. Focus can escape to elements behind the backdrop. The input has no `aria-label`. The overlay click-to-close works but there's no announcement for screen readers. **Fix:** Add dialog ARIA attributes, implement focus trap (like ConfirmModal does), add `aria-label` to the search input. |
| P1 | `Layouts/AdminLayout.vue`, `Layouts/PortalLayout.vue` | No skip-navigation link (`<a href="#main-content" class="sr-only focus:not-sr-only">Skip to content</a>`). Every page load forces keyboard users through all sidebar items. |
| P1 | `Components/Admin/Sidebar.vue:134` | Sidebar nav icon SVGs are rendered via `v-html` with no `aria-hidden="true"` on the containing `<span>`. Screen readers will attempt to announce the SVG markup. |
| P1 | `Components/ThemeToggle.vue` | The toggle button has a `title` but no `aria-label`. It uses `title` as the accessible name, which is not reliably announced by all screen readers. It also has no `aria-pressed` or `role="switch"` to communicate state. |
| P2 | `Components/UI/FilterBar.vue` | The search input has no `<label>` element or `aria-label`. Filter `<select>` elements have no associated `<label>` -- they rely on the placeholder option text which is not an accessible label. |
| P2 | `Components/UserMenu.vue` | The dropdown trigger has no `aria-expanded`, `aria-haspopup="menu"`, or `aria-controls`. The dropdown items have no `role="menuitem"`. No keyboard navigation within the menu (arrow keys). |
| P2 | `Pages/Admin/Content/Pages/Index.vue` | The table is built with `<div>` elements styled as a table. Screen readers cannot navigate it as a table. **Fix:** Use semantic `<table>` or DataTable component. |
| P3 | `Components/UI/Pagination.vue:36-42` | Pagination links use `v-html` to render the label (which includes `&laquo;` / `&raquo;` entities from Laravel). This works but the previous/next links have no `aria-label="Previous page"` / `aria-label="Next page"` -- screen readers announce the raw entities. |
| P3 | `Pages/Admin/Switches/Ports/Show.vue` | Port navigation links use `&larr;` and `&rarr;` HTML entities as the only visible content. These should have `aria-label` attributes (e.g., "Previous port: Gi0/1"). |

---

## 4. Responsive Design

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P1 | `Pages/Admin/Dashboard.vue:89` | The stat strip uses `flex` with fixed `mr-8 pr-8 border-r` on each stat card. On screens below ~768px this overflows horizontally with no scroll, wrap, or grid fallback. Same issue on `Users/Index.vue`, `Switches/Index.vue`, and `Dhcp/Index.vue` summary strips. |
| P1 | `Pages/Admin/Dashboard.vue:95` | The DHCP/Unique IPs section uses `grid-cols-[3fr_2fr]` with no responsive breakpoint. On mobile this renders two cramped columns. Should stack to single column below `md`. |
| P1 | `Components/UI/MetadataStrip.vue` | Uses `flex` with `mr-8 pr-8 border-r` between items. On narrow viewports, metadata items overflow. Needs `flex-wrap` or a responsive grid. This component is used on IP Show, User Show, Switch Show, and Port Show pages. |
| P2 | `Layouts/AdminLayout.vue:36` | Main content area uses `px-10` which is too much horizontal padding on small screens. The portal layout correctly uses `px-6`. Should use `px-6 md:px-10`. |
| P2 | `Pages/Admin/Switches/Ports/Show.vue:298` | The two-column layout uses `xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]` which is responsive, but the single-column fallback stacks bandwidth charts and connected devices in a very long vertical scroll. Consider prioritizing content order. |
| P2 | `Components/Admin/Sidebar.vue` | The mobile horizontal nav renders all nav groups with their labels inline, creating a very wide scrollable area. On small phones (320px) the group labels (`MANAGEMENT`, `SERVICES`, `CONTENT`) consume significant space. Consider hiding group labels on mobile or using a hamburger menu. |
| P3 | `Pages/Admin/Settings/IntegrationShow.vue` | The capabilities reference grid uses `sm:grid-cols-2 lg:grid-cols-3` which is good, but the health log table has no responsive treatment and will overflow on mobile. |

---

## 5. Design System Adherence

### Strengths

- The OKLCH color system is exceptionally well implemented. The `dispatch.css` theme file defines a complete dark and light mode with warm-charcoal undertones, and every component references CSS custom properties.
- The accent color system with runtime hue/chroma/lightness adjustment via `useAccentColor.js` is sophisticated and well-integrated.
- There are no card wrappers, accent lines, or `border-l` decorative patterns -- the design correctly follows the Dispatch system's flat, surface-based approach.
- `--color-input-bg` is defined but underused (only in MarkdownEditor). Most inputs use `bg-[var(--color-surface)]` which is correct for the admin context.

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P2 | `Pages/Auth/Login.vue:186` | `text-red-500` is a Tailwind utility color, not a theme variable. Should be `text-[var(--color-danger)]`. |
| P3 | `Pages/Admin/Settings/Theme.vue:247` | The save button uses `text-white` instead of `text-[var(--color-accent-text)]` or `text-[var(--color-bg)]`. On extreme accent color choices, white text may not contrast. |
| P3 | `Pages/Admin/Ips/Create.vue:77` | Submit button uses `text-white` instead of `text-[var(--color-bg)]` or `text-[var(--color-accent-text)]`. Same issue. |
| P3 | Multiple files | Several primary buttons use `text-[var(--color-bg)]` while others use `text-white`. The `--color-accent-text` token exists specifically for this purpose but is rarely used. A consistent approach would improve maintainability. |

---

## 6. Information Architecture

### Strengths

- The sidebar navigation is logically organized into three groups: Management (Dashboard, Users, IPs, Switches, DHCP), Services (Integrations, IPv6 Detection, DNS Detection), and Content (Dashboard, Pages, Theme, Settings).
- Breadcrumbs are implemented via server-side props and consistently rendered in the admin header.
- Page hierarchy follows a clear Index > Show > Edit pattern.

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P2 | `Components/Admin/Sidebar.vue:31-38` | "Services" group contains items that are actually settings pages (`/admin/settings/integrations`, `/admin/settings/ipv6-detection`, `/admin/settings/dns-detection`). This conflates service management with configuration. Consider renaming to "Configuration" or "Settings" for clarity. |
| P2 | `Components/Admin/Sidebar.vue:42-47` | Two nav items are labeled "Dashboard" -- one under Management and one under Content. This creates ambiguity. The Content Dashboard could be renamed "Content Editor" or "Portal Editor". |
| P2 | `Components/Admin/Sidebar.vue:44-45` | "Theme" and "Settings" under the Content group link to `/admin/settings/theme` and `/admin/content/settings` respectively. The URL structure doesn't match the nav grouping -- theme is under settings/ while content settings is under content/. This is confusing for users who notice URLs. |
| P3 | `Pages/Admin/Ips/Index.vue` | The IP list page has no "Add IP" button in the header, unlike Switches/Index which has "Add Switch". Users must know the URL directly. There is an `Ips/Create.vue` page that exists but is not linked from the index. |

---

## 7. Interaction Design

### Strengths

- Confirmation modals are used for destructive actions (block/unblock user, revoke/grant IP access, shut/unshut port). These include loading states, variant-colored buttons, and contextual messages.
- Forms show inline validation errors via the FormField component.
- Loading states exist for: form submission (button text changes), chart loading (spinner), deferred data (skeleton placeholders), and async operations (Test Connection, Sync).
- Flash messages auto-dismiss for success/info types and persist for warning/error types.

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P2 | `Pages/Account/Settings.vue:60` | `clearPassword()` and `deletePasskey()` use `window.confirm()` instead of the ConfirmModal component. These are destructive security-sensitive actions that deserve the same treatment as block/unblock. |
| P2 | `Pages/Admin/Ips/Index.vue:52-62` | Search requires pressing Enter to execute (`@keyup.enter="search"`). This is inconsistent with Users/Index where filtering is immediate on keystroke. Users who type and wait will see no results update. |
| P2 | `Components/Admin/GlobalSearch.vue` | No loading indicator is shown while search results are being fetched. The `loading` ref exists but is never rendered in the template. Users see no feedback between typing and results appearing. |
| P2 | `Components/Admin/GlobalSearch.vue:33-36` | Search errors are silently swallowed (`catch (_e) { // Silently fail }`). If the search endpoint is down, users get no feedback. |
| P3 | `Pages/Admin/Switches/Show.vue` | The "Sync Now" button triggers a full Inertia POST but provides no visual feedback after completion (no flash message on success, no inline confirmation). The button label changes during the operation but reverts silently. |
| P3 | `Pages/Admin/Users/Edit.vue` | The cancel button is an `<a>` tag while the submit button is a `<button>`. This means cancel triggers a full page navigation rather than using Inertia's `Link` component. The cancel action should confirm if the form has unsaved changes. |
| P3 | `Components/UI/ConfirmModal.vue` | Clicking the overlay backdrop does not dismiss the modal. Only the Escape key or Cancel button work. This is unexpected -- most modal patterns support click-outside-to-close. |

---

## 8. Typography & Hierarchy

### Strengths

- Page titles are consistently styled: `font-heading text-[32px] font-bold tracking-[-0.03em]` with optical sizing at 48.
- Section headers use a consistent uppercase label pattern: `text-[14px] font-bold tracking-[0.04em] uppercase` with optical sizing at 16.
- Stat card labels use `text-[10px] font-semibold tracking-[0.06em] uppercase` creating a clear micro-label pattern.
- Body text consistently uses `text-[13px]` for table cells and descriptions, `text-[11px]` for meta information, `text-[12px]` for secondary data.

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P2 | Multiple pages | Section headers (`h2`) are created inline rather than using the `SectionHeader` component. `Ips/Index.vue:99`, `Switches/Index.vue:241`, `Dhcp/Index.vue:94`, and multiple sections in `Switches/Create.vue` all duplicate the section header pattern manually. This makes future style changes require touching many files. |
| P3 | `Pages/Portal/Dashboard.vue:31` | Portal page title uses `text-[28px]` while all admin pages use `text-[32px]`. This is likely intentional (portal is more compact) but creates an unexplained inconsistency. |
| P3 | `Pages/Admin/Users/Index.vue:97-131` | The summary strip manually creates stat-like displays instead of using the `StatCard` component. The font size (`text-[20px]`) differs from Dashboard's StatCard (`text-[28px]`). |
| P3 | `Pages/Account/Settings.vue` | Uses `text-2xl` (Tailwind utility) for headings and `text-sm` / `text-xs` for body, while the admin side uses explicit pixel sizes (`text-[32px]`, `text-[13px]`). The Account Settings page follows a different sizing convention. |

---

## 9. Anti-patterns

### Issues

| Severity | File | Issue |
|----------|------|-------|
| P2 | `Components/Admin/Sidebar.vue:11-18` | Inline SVG icons are stored as raw HTML strings and rendered via `v-html`. This is an XSS vector (though in this case the data is static), prevents Vue's reactivity from applying to icon internals, and makes the icons impossible to tree-shake. **Fix:** Use a dedicated icon component or SVG sprite system. |
| P2 | `Pages/Admin/Switches/Show.vue:254` | References `latestSync` and `canDownloadConfig` in the template but these are never defined in `<script setup>`. This would cause runtime errors when these sections are rendered. These appear to be template remnants from a refactor. |
| P2 | `Pages/Admin/Switches/Ports/Show.vue:66-67` | `getThemeColor()` reads computed styles synchronously at render time. This won't update when the theme changes (e.g., when toggling light/dark mode) because the computed values are cached in the Chart.js instance. The chart would need to be rebuilt on theme change. |
| P3 | `Components/Admin/GlobalSearch.vue:9` | `debounceTimer` is declared as a module-level `let` variable. If multiple instances of GlobalSearch existed (they don't currently, but the component doesn't prevent it), they would share this timer causing race conditions. Should use `ref()` or scope it in `onMounted`. |
| P3 | `Pages/Admin/Switches/Create.vue:13` | `useForm` is wrapped in `reactive()` which is unnecessary and can cause double-reactivity issues. `useForm` already returns a reactive proxy. |
| P3 | `Components/BlockGrid.vue:67-73` | Portal dashboard blocks use card wrappers with `rounded-md border bg-[var(--color-surface)] p-5 hover:border-[var(--color-border-hover)]`. This is the only place in the app that uses hover-on-container-border as an interaction pattern, creating a card-like affordance that suggests clickability when blocks are not clickable. |

---

## 10. Page-by-Page Notes

### Login (`Pages/Auth/Login.vue`)

- **P3:** The passkey section description text ("Use a passkey to sign in securely") is only shown when there's no error. After an error and retry, the helpful text disappears permanently until page reload.
- **P3:** No "forgot password" link exists. If this is intentional (admin-only tool), consider adding a brief note explaining recovery options.

### Admin Dashboard (`Pages/Admin/Dashboard.vue`)

- **P2:** The `Deferred` data loading pattern is well-used but the skeleton loaders are generic rectangles. Consider matching the skeleton shape to the actual content (e.g., table row skeletons for the recent users section).
- **P3:** Recent users table includes a link to each user but the DataTable's `clickable` prop is not set, making only the nickname link clickable rather than the entire row.

### Users Index (`Pages/Admin/Users/Index.vue`)

- **P2:** Filtering is entirely client-side (`computed` on the current page's data). If the users list spans multiple pages, filtered counts will only reflect the current page, giving misleading "3 of 25" counts.
- **P3:** The page subtitle "Manage registered user accounts." is shown but the Users page offers no management actions (no create, no bulk actions). The subtitle overpromises.

### Users Show (`Pages/Admin/Users/Show.vue`)

- **P3:** The "Edit" button is an `<a>` tag instead of an Inertia `Link`, causing a full page reload on navigation.

### IP Addresses Index (`Pages/Admin/Ips/Index.vue`)

- **P2:** No "Add IP" button in the header, even though `Ips/Create.vue` exists. Users cannot discover this feature.
- **P3:** The inline status display (dot + label) is semantically identical to what StatusPill provides but uses a custom implementation. Inconsistent with Users Index which uses StatusPill.

### IP Show (`Pages/Admin/Ips/Show.vue`)

- **P3:** The `togglePort` function exists but is never called from the template. Dead code from a previous implementation.

### Switches Index (`Pages/Admin/Switches/Index.vue`)

- **P1:** (Previously noted) Builds a full custom table instead of using DataTable.
- **P3:** Port breakdown cell shows up/down/error arrows without any tooltip or legend. New users won't know what the symbols mean.

### Switches Show (`Pages/Admin/Switches/Show.vue`)

- **P2:** References `latestSync`, `canDownloadConfig`, `showConfig`, and `runningConfig` in the template without defining them in `<script setup>`. These will cause Vue runtime warnings or errors. This appears to be broken code.
- **P3:** The ports section builds its own filter/search UI inline rather than using FilterBar, duplicating the search input and filter select patterns.

### Port Show (`Pages/Admin/Switches/Ports/Show.vue`)

- **P3:** Auto-refresh runs every 30 seconds via `setInterval`. This is aggressive and will generate constant network traffic even when the user has navigated away (the interval is cleared on unmount, but during active viewing it's invisible). Consider showing the auto-refresh interval or providing a manual-only option.

### DHCP Index (`Pages/Admin/Dhcp/Index.vue`)

- Good: Uses DataTable with sorting, has a usage progress bar with color-coded thresholds.
- **P3:** No empty state handling for the MetadataStrip -- if `ranges.length === 0`, the summary strip is correctly hidden.

### Content Pages Index (`Pages/Admin/Content/Pages/Index.vue`)

- **P2:** Uses `<div>` elements styled as a table instead of semantic `<table>` or DataTable. Screen readers cannot navigate this as tabular data.
- **P3:** The empty state CTA button duplicates the header's "New Page" button verbatim. Consider removing the header button when showing the empty state.

### Theme Settings (`Pages/Admin/Settings/Theme.vue`)

- **P3:** The range slider inputs (`<input type="range">`) have no `aria-label` or `aria-valuetext`. Screen readers announce them as unlabeled sliders.
- **P3:** The accent preset buttons are color-only with `title` attribute but no visible text label. Users with color vision deficiency cannot distinguish presets.

### Integration Show (`Pages/Admin/Settings/IntegrationShow.vue`)

- Good: Well-structured with configuration, capabilities, and health log sections.
- **P3:** The health log table has no pagination. If a service has hundreds of log entries, the page could become very long.

### Account Settings (`Pages/Account/Settings.vue`)

- **P2:** Uses a computed layout component (`AdminLayout` vs `PortalLayout` based on role). This works but means the page renders differently for admins vs regular users without any visual indication of why.
- **P2:** Uses `window.confirm()` for destructive actions (see Interaction Design section).

### Portal Dashboard (`Pages/Portal/Dashboard.vue`)

- **P3:** The portal dashboard is thin -- just a heading, optional DNS warning, and a block grid. If no blocks are configured, the page shows an empty grid with no guidance.

---

## Summary of Findings by Severity

| Severity | Count | Description |
|----------|-------|-------------|
| P0       | 1     | GlobalSearch missing dialog ARIA, focus trap |
| P1       | 6     | Skip-nav missing; stat strip mobile overflow; MetadataStrip mobile overflow; Sidebar icon a11y; Switches using custom table; Dashboard grid not responsive |
| P2       | 21    | Hardcoded color; FilterBar inconsistency; MarkdownEditor regex fragility; window.confirm usage; Search UX inconsistency; GlobalSearch no loading/error; Broken template refs in Switch Show; Content Pages non-semantic table; Client-side filtering accuracy; Missing "Add IP" button; Inline section headers; Account Settings layout; duplicate table patterns |
| P3       | 24    | Minor inconsistencies in border radius, font sizing, dead code, missing aria-labels on secondary elements |

---

## Recommended Priority Order

1. **Immediate (P0):** Fix GlobalSearch accessibility -- add `role="dialog"`, `aria-modal`, focus trap, and `aria-label` on input.

2. **Next sprint (P1):**
   - Add skip-navigation link to both layouts.
   - Make stat strips and MetadataStrip responsive (add `flex-wrap`, adjust spacing at breakpoints).
   - Add `aria-hidden="true"` to sidebar icon spans.
   - Refactor Switches/Index to use DataTable.
   - Add responsive breakpoint to Dashboard grid (`grid-cols-[3fr_2fr]` should be `grid-cols-1 md:grid-cols-[3fr_2fr]`).

3. **Following sprint (P2):**
   - Fix `text-red-500` in Login.vue.
   - Replace `window.confirm()` with ConfirmModal in Account Settings.
   - Add loading indicator and error state to GlobalSearch.
   - Fix broken template references in Switches/Show.vue.
   - Unify search/filter behavior (debounced immediate vs enter-to-submit).
   - Replace MarkdownEditor regex with a proper Markdown parser.
   - Add UserMenu ARIA attributes and keyboard navigation.
   - Add FilterBar input labels.
   - Convert Content Pages Index to use semantic table/DataTable.
   - Add "Add IP" button to IP Index header.
   - Refactor inline section headers to use SectionHeader component.

4. **Backlog (P3):**
   - Standardize button text colors to use `--color-accent-text` token.
   - Add `aria-label` to pagination previous/next links.
   - Clean up dead code (`togglePort` in IP Show, `reactive(useForm())` in Switches/Create).
   - Address remaining minor consistency items.
