# Dispatch Design System

Reference for Aperture's "Dispatch" design direction. This document covers the complete visual system — colors, typography, spacing, components, layout rules, and anti-patterns. Any agent building new pages or modifying existing ones should follow this guide.

## Design Philosophy

Dispatch is an **operations console** aesthetic. Dense, hierarchical, zero decorative elements. Information is organized through **spacing and typography contrast**, not through card containers or accent lines. The interface should feel like a professional monitoring tool — confident, fast, no fluff.

Two audiences:
- **Admins**: Network staff managing infrastructure during live events. Dark mode primary, data-dense, scannable.
- **Portal users**: LAN attendees checking their connection. Gaming-adjacent energy, but still efficient.

## Color System

### OKLCH Color Space

All colors use OKLCH (perceptually uniform). The theme file is `resources/css/themes/dispatch.css`.

Colors are applied via CSS custom properties on `[data-theme='dispatch']` (dark) and `[data-theme='dispatch'][data-mode='light']` (light).

### Base Palette — Dark Mode (Primary)

| Variable | Value | Purpose |
|---|---|---|
| `--color-bg` | `oklch(13% 0.006 60)` | Page background |
| `--color-surface` | `oklch(17% 0.006 60)` | Elevated surfaces (sidebar, cards) |
| `--color-surface-hover` | `oklch(21% 0.006 60)` | Hover state for surfaces |
| `--color-surface-alt` | `oklch(20% 0.008 60)` | Alternate surface (subtle distinction) |
| `--color-text` | `oklch(95% 0.006 60)` | Primary text |
| `--color-text-secondary` | `oklch(75% 0.006 60)` | Secondary text |
| `--color-text-muted` | `oklch(55% 0.006 60)` | Muted labels, captions |
| `--color-border` | `oklch(26% 0.004 60)` | Hairline borders, dividers |
| `--color-border-hover` | `oklch(33% 0.004 60)` | Hover state for borders |
| `--color-input-bg` | `oklch(15% 0.006 60)` | Form input backgrounds |

Note: All neutrals are **warm charcoal** (hue 60), not cold slate. Even at low chroma (0.004–0.008), this warmth is perceptible and creates cohesion with the accent system.

### Base Palette — Light Mode

| Variable | Value | Purpose |
|---|---|---|
| `--color-bg` | `oklch(97% 0.004 60)` | Page background |
| `--color-surface` | `oklch(100% 0 0)` | Elevated surfaces |
| `--color-surface-hover` | `oklch(95% 0.004 60)` | Hover state |
| `--color-surface-alt` | `oklch(96% 0.005 60)` | Alternate surface |
| `--color-text` | `oklch(15% 0.006 60)` | Primary text |
| `--color-text-secondary` | `oklch(40% 0.006 60)` | Secondary text |
| `--color-text-muted` | `oklch(55% 0.006 60)` | Muted labels |
| `--color-border` | `oklch(88% 0.004 60)` | Borders |
| `--color-border-hover` | `oklch(80% 0.004 60)` | Border hover |
| `--color-input-bg` | `oklch(100% 0 0)` | Input backgrounds |

### Semantic Colors

These convey meaning and are **never used decoratively**.

| Variable | Dark Value | Light Value | Usage |
|---|---|---|---|
| `--color-success` | `oklch(72% 0.17 155)` | `oklch(48% 0.15 155)` | Connected, enabled, healthy |
| `--color-warning` | `oklch(78% 0.15 85)` | `oklch(55% 0.14 85)` | Caution, degraded |
| `--color-danger` | `oklch(65% 0.2 25)` | `oklch(50% 0.2 25)` | Error, disabled, critical |
| `--color-info` | `oklch(70% 0.1 240)` | `oklch(50% 0.1 240)` | Informational |

### Accent / Primary Colors

These are **dynamically computed** from a single integer (0–360 hue). The admin configures this per event.

| Variable | Purpose |
|---|---|
| `--color-primary` | Primary interactive elements, links, active states |
| `--color-primary-hover` | Hover state for primary elements |
| `--color-accent` | Alias for primary (used interchangeably) |
| `--color-accent-hover` | Hover state for accent |
| `--color-accent-dim` | Sidebar active background (14% opacity dark, 10% light) |
| `--color-accent-text` | Text on accent-colored backgrounds |
| `--color-glow` | Subtle glow effects (25% opacity dark, 15% light) |

Static defaults in CSS use hue 55 (tangerine). At runtime, `useAccentHue.js` overrides these on `document.documentElement`.

## Accent Hue System

### How It Works

1. Admin sets an accent hue (integer 0–360) via Settings > Appearance
2. Backend stores it (default: 55) and shares it via Inertia props as `theme.accent_hue`
3. Frontend composable `useAccentHue.js` reads this value and sets 7 CSS variables on `<html>`
4. For captive portal (Blade, no JS framework): PHP computes the same values and injects them as inline `<style>` in `layouts/captive.blade.php`

### Presets

Each preset has **tuned lightness and chroma** — not just different hues at the same L/C.

| Name | Hue | Lightness | Chroma |
|---|---|---|---|
| Pink | 350 | 72 | 0.19 |
| Coral | 20 | 73 | 0.17 |
| **Tangerine** (default) | 55 | 76 | 0.16 |
| Lime | 135 | 80 | 0.18 |
| Teal | 185 | 76 | 0.12 |
| Sky | 230 | 72 | 0.14 |
| Violet | 295 | 70 | 0.18 |
| Magenta | 325 | 70 | 0.20 |

### Dark vs Light Mode Derivation

**Dark mode** (values above are dark mode):
```
--color-primary:       oklch({l}% {c} {hue})
--color-primary-hover:  oklch({l-7}% {c+0.03} {hue})
--color-accent-dim:    oklch({l}% {c} {hue} / 0.14)
--color-accent-text:   oklch(98% 0.01 {hue})
--color-glow:          oklch({l}% {c} {hue} / 0.25)
```

**Light mode** — lightness drops 21 points, chroma increases 0.02:
```
lightL = max(l - 21, 40)
lightC = c + 0.02

--color-primary:       oklch({lightL}% {lightC} {hue})
--color-primary-hover:  oklch({lightL-7}% {lightC+0.02} {hue})
--color-accent-dim:    oklch({lightL}% {lightC} {hue} / 0.1)
--color-accent-text:   oklch(99% 0.005 {hue})
--color-glow:          oklch({lightL}% {lightC} {hue} / 0.15)
```

### Adding a New Preset

In `resources/js/composables/useAccentHue.js`, add to the `ACCENT_PRESETS` array:

```js
{ name: 'Gold', hue: 70, l: 78, c: 0.15 },
```

Rules for tuning:
- **Lightness (l)**: Stay in 70–80 range for dark mode readability. Higher = more vibrant on dark backgrounds.
- **Chroma (c)**: 0.12–0.20. Lower chroma for cool hues (Teal: 0.12), higher for warm hues (Magenta: 0.20).
- **Test both modes**: The light mode derivation subtracts 21 from lightness — make sure the result still passes contrast on white.

### Custom (Non-Preset) Hues

If the admin picks a hue that doesn't match any preset, fallback values are used: `l=72, c=0.19`. The slider allows any integer 0–360.

## Typography

### Font Stack

| Token | Family | Usage | Weight Range |
|---|---|---|---|
| `--font-heading` | Bricolage Grotesque (variable) | Section headers, page titles | 200–800 |
| `--font-body` | Hanken Grotesk (variable) | Body text, labels, UI chrome | 100–900 |
| `--font-mono` | JetBrains Mono | Data values, code, IPs, MACs | 400 |

All fonts are **self-hosted** in `resources/fonts/`. This is required — LAN captive portal users have no internet access.

### Type Scale

| Element | Family | Size | Weight | Transform | Tracking |
|---|---|---|---|---|---|
| Page title | heading | `text-lg` (18px) | bold (700) | — | — |
| Section header | heading | `text-sm` (14px) | bold (700) | uppercase | `tracking-wider` |
| Metadata label | body | `10px` | bold (700) | uppercase | `tracking-wider` |
| Body text | body | `text-sm` (14px) | normal (400) | — | — |
| Small text | body | `text-[13px]` | normal (400) | — | — |
| Muted caption | body | `text-[11px]` | normal (400) | — | — |
| Stat value | mono | `text-lg` (18px) | semibold (600) | — | — |
| Table header | body | `text-xs` (12px) | bold (700) | uppercase | `tracking-wider` |
| Table cell data | mono | `text-[11px]`–`text-sm` | normal (400) | — | — |
| Breadcrumbs | mono | `text-[13px]` | normal (400) | — | — |

### Typography Rules

- **Headings**: Always `font-heading` (Bricolage Grotesque)
- **Labels and metadata**: `uppercase tracking-wider font-bold text-[10px]` in `--color-text-muted`
- **Data values**: `font-mono` — IPs, MACs, port names, speeds, byte counts
- **Body**: `font-body` (Hanken Grotesk) at 14px default
- **Never use Inter, Space Grotesk, or Plus Jakarta Sans** — these were replaced

## Component Patterns

### SectionHeader

Titles sections using **size contrast only** — no accent lines, no decorative borders.

```vue
<SectionHeader title="Connected Devices">
    <template #actions>
        <button>Action</button>
    </template>
</SectionHeader>
```

Renders as: `font-heading text-sm font-bold tracking-wider uppercase` in `--color-text-muted`.

### StatCard

Open layout — label + value, no card container.

```vue
<StatCard label="Total Users" :value="42" color="primary" />
```

- Label: `10px uppercase tracking-wider font-bold` in muted
- Value: `font-mono text-lg font-semibold` in specified color
- Optional `labelDotColor` prop adds a small colored dot before the label

### MetadataStrip

Horizontal bar of key-value pairs separated by a bottom border.

```vue
<MetadataStrip :items="[
    { label: 'Status', value: 'Connected' },
    { label: 'IP Address', value: '10.0.0.42', mono: true },
    { label: 'Hostname', value: 'gamer-pc', large: true },
]" />
```

- Each item: label above (10px muted uppercase), value below (13px)
- `mono: true` applies `font-mono` to value
- `large: true` applies `text-sm font-semibold` to value
- Bottom border: `border-b-2 border-[var(--color-border)]`
- Gap: `gap-x-7 gap-y-2`, wraps on small screens

### DataTable

Clean table with hairline row dividers, no outer card container.

```vue
<DataTable :columns="columns" :rows="rows" clickable :row-href="row => route('...')" />
```

- Header: `border-b-2`, `text-xs uppercase tracking-wider font-bold` in muted
- Rows: `border-b border-[var(--color-border)]`, last row has no bottom border
- Clickable rows: `cursor-pointer hover:bg-[var(--color-surface-hover)]`
- No `border-l-2` hover accents — bg fill only

### AlertBanner

Subtle background tint with full border. No left accent stripe.

```vue
<AlertBanner type="warning">
    <p>DNS misconfiguration detected</p>
</AlertBanner>
```

Types: `warning`, `info`, `danger`, `success`. Each uses 5% bg opacity + 20% border opacity of the semantic color. Rounded corners (`rounded-lg`).

### StatusPill

Color-coded status indicators. Uses CSS variables, supports 5 variants.

### EmptyState

Simple text layout — no dashed border card, no decorative container.

### ProgressBar

Used for DHCP pool utilization. Color prop accepts `primary`, `warning`, `danger`.

## Layout Rules

### No Card Containers

This is the most important rule. **Do not wrap content in card containers** (no `bg-surface rounded-xl border p-4` wrappers). Sections are separated by:

1. **Spacing** — generous vertical gaps between sections (`space-y-8`, `space-y-10`)
2. **Typography** — SectionHeader component with uppercase muted text
3. **Hairline borders** — `border-b border-[var(--color-border)]` when groups need visual separation

### Admin Layout Structure

```
┌──────────────────────────────────────────────┐
│ 48px topbar (brand left, mode toggle right)  │
├─────────┬────────────────────────────────────┤
│ 220px   │ Breadcrumbs bar                    │
│ sidebar │────────────────────────────────────│
│         │ Main content                       │
│         │ max-w-[1400px] px-10 pt-8 pb-16    │
│         │                                    │
└─────────┴────────────────────────────────────┘
```

- Sidebar: 220px wide, `bg-[var(--color-surface)]`
- Topbar: 48px, `bg-[var(--color-surface)]`, full width
- Breadcrumbs: `font-mono text-[13px]`, below topbar, above content
- Main content: `max-w-[1400px]`, centered, `px-10 pt-8 pb-16`

### Sidebar Active State

**Do**: `bg-[var(--color-accent-dim)] text-[var(--color-primary)] font-semibold`  
**Don't**: `border-l-2 border-[var(--color-primary)] bg-[var(--color-primary)]/10`

Active items use a subtle accent-tinted background fill. No left border indicator.

### Settings Navigation

Same pattern as sidebar — active items use `bg-[var(--color-accent-dim)]`, no border-l.

### Page Structure Pattern

Most admin pages follow this pattern:

```vue
<template>
    <AdminLayout>
        <!-- Page title -->
        <h1 class="font-heading text-lg font-bold">Page Title</h1>
        
        <!-- Optional: MetadataStrip -->
        <MetadataStrip :items="[...]" />
        
        <!-- Content sections, separated by spacing -->
        <div class="mt-8 space-y-10">
            <section>
                <SectionHeader title="Section Name" />
                <!-- section content -->
            </section>
            
            <section>
                <SectionHeader title="Another Section" />
                <!-- section content -->
            </section>
        </div>
    </AdminLayout>
</template>
```

### Detail Pages

Detail pages (User, IP, Port) use MetadataStrip for key attributes, then sections below:

```
┌──────────────────────────────────┐
│ h1: Entity Name                  │
│ MetadataStrip: key-value pairs   │
├──────────────────────────────────┤
│ Section 1                        │
│ Section 2                        │
│ ...                              │
└──────────────────────────────────┘
```

### List Pages

List pages (Users, IPs, Switches, DHCP) use FilterBar + DataTable:

```
┌──────────────────────────────────┐
│ h1: Page Title                   │
│ FilterBar (search + filters)     │
│ DataTable (full-width, no card)  │
│ Pagination                       │
└──────────────────────────────────┘
```

## Anti-Patterns — What NOT to Do

### Banned: Card Containers
```vue
<!-- DON'T -->
<div class="rounded-xl border bg-[var(--color-surface)] p-4">
    <h3>Section</h3>
    <content />
</div>

<!-- DO -->
<SectionHeader title="Section" />
<content />
```

### Banned: Accent-Line Headers
```vue
<!-- DON'T -->
<div class="border-l-3 border-[var(--color-primary)] pl-3">
    <h3>Title</h3>
</div>

<!-- DO -->
<SectionHeader title="Title" />
```

### Banned: Left Border Active States
```vue
<!-- DON'T -->
<a class="border-l-2 border-[var(--color-primary)] bg-[var(--color-primary)]/10">

<!-- DO -->
<a class="bg-[var(--color-accent-dim)] text-[var(--color-primary)] font-semibold">
```

### Banned: Gradient Text
```css
/* DON'T */
background: linear-gradient(...);
-webkit-background-clip: text;
color: transparent;
```

### Banned: Glassmorphism / Neon Glow
```css
/* DON'T */
backdrop-filter: blur(10px);
box-shadow: 0 0 20px var(--color-primary);
```

### Banned: Nested Cards
```vue
<!-- DON'T — cards inside cards -->
<div class="rounded-xl border p-4">
    <div class="rounded-lg border p-3">...</div>
</div>
```

### Banned: Decorative Elements
- No sparklines as decoration
- No large rounded-corner icons above headings
- No generic drop shadows on containers
- No hero metric layout (big number, small label, gradient accent)

## File Reference

| File | Purpose |
|---|---|
| `resources/css/themes/dispatch.css` | OKLCH theme — all CSS variable definitions |
| `resources/css/app.css` | Font imports, Tailwind config, base styles |
| `resources/js/composables/useAccentHue.js` | Accent hue runtime application + presets |
| `resources/js/composables/useTheme.js` | Theme mode management (dark/light toggle) |
| `resources/fonts/bricolage-grotesque/` | Heading font (variable, woff2) |
| `resources/fonts/hanken-grotesk/` | Body font (variable, woff2) |
| `resources/fonts/jetbrains-mono/` | Mono font (woff2) |
| `resources/views/app.blade.php` | Root Blade template (`data-theme="dispatch"`) |
| `resources/views/layouts/captive.blade.php` | Captive portal layout (server-side accent injection) |
| `app/Http/Middleware/InjectTheme.php` | Shares theme mode + accent_hue to views |
| `app/Http/Controllers/Admin/ThemeSettingsController.php` | Validates accent_hue (int 0–360) |

## Testing Conventions

- All interactive/testable elements have `data-testid` attributes
- Convention: `{component}-{element}` (e.g., `data-table-row`, `stat-value`, `section-header`)
- Tests use Vitest (JS) and PHPUnit (PHP)
- UI components are tested for rendering, prop behavior, and accessibility
- Playwright for E2E across chromium, mobile (iPhone 13), tablet (iPad gen 7)

---

*Last updated: 2026-04-20. Dispatch direction approved and implemented across all admin and portal pages.*
