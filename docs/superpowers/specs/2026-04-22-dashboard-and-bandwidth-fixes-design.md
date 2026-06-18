# Dashboard & Bandwidth Fixes Design

## Goal

Fix bandwidth graphs, improve the accent color picker, fix markdown link styling, and add bandwidth graphs to the admin IP page. Five changes that share the bandwidth/theming surface area.

## 1. Accent Color Picker — Add Saturation & Lightness

### Problem

The hue slider only controls hue (0–360). Non-preset hues default to L=72%, C=0.19 which produces washed-out or dull results for many hues. Users cannot select bolder colours.

### Design

Add two sliders below the existing hue slider:

- **Saturation** (OKLCH chroma): range 0.01–0.37, step 0.01
- **Lightness** (OKLCH lightness): range 40–95, step 1

Behavior:
- Clicking a preset sets all three sliders to that preset's H/C/L values
- Moving any slider manually clears the preset selection ring (no preset is "active")
- Gradient backgrounds on S/L sliders update dynamically as other values change
- A preview swatch next to the sliders shows the live result with OKLCH values

### Storage

Add two new settings alongside `theme.accent_hue`:
- `theme.accent_chroma` (float, default 0.19) — stored as string, cast to float
- `theme.accent_lightness` (integer, default 72)

### `applyAccentHue()` → `applyAccentColor()`

Rename to `applyAccentColor(hue, chroma, lightness, mode)`. Removes preset lookup — always uses the three provided values directly.

Light mode adjustment formula (unchanged logic, applied to user values):
- `lightL = max(lightness - 21, 40)`
- `lightC = chroma + 0.02`

The composable `useAccentHue` becomes `useAccentColor` with refs for all three values. Presets remain as a convenience — clicking one sets all three refs.

### Theme Middleware

`InjectTheme` middleware shares three values instead of one:
```php
'theme' => [
    'mode' => ...,
    'accent_hue' => ...,
    'accent_chroma' => ...,
    'accent_lightness' => ...,
],
```

### Validation

- `accent_hue`: integer, 0–360
- `accent_chroma`: numeric, 0.01–0.37
- `accent_lightness`: integer, 40–95

## 2. Dashboard Markdown Links — Fix Accent Color

### Problem

Links rendered in dashboard markdown blocks do not pick up the accent colour.

### Fix

The `CustomMarkdownBlock` component wraps content in `prose prose-sm` classes, and `app.css` has `.prose a { color: var(--color-accent) }`. If the rule isn't applying, it's a CSS specificity issue — Tailwind v4 typography plugin styles may override custom rules.

Fix by ensuring the `.prose a` rule in `app.css` has sufficient specificity. If the `@plugin` directive generates conflicting styles, override with `.prose :where(a)` or increase specificity to `.prose a:any-link`.

Verify by checking the rendered output in browser dev tools and adjusting specificity as needed.

## 3. Admin IP Bandwidth Graph

### Backend

Add a `bandwidth` method to `IpAddressController`:

```
GET /admin/ips/{ip}/bandwidth?range=24h
```

- Accepts `range` query parameter: `1h`, `24h`, `4d`
- Calls `TrafficMonitorInterface::getUserBandwidth($ip->address, $range)`
- Returns same JSON shape as portal endpoint: `{ timestamps, download, upload, totalReceived, totalSent }`
- Route name: `admin.ips.bandwidth`

### Frontend

Add a bandwidth section to `Admin/Ips/Show.vue`:

- **Range selector**: three buttons — 1h | 24h | 4d. Default: 24h. Active button gets accent styling.
- **Stats row**: Total downloaded / uploaded displayed with `formatBytes()`
- **Chart**: `TimeSeriesChart` component with download (green) and upload (blue) series
- Fetches data on mount and when range selection changes
- Shows loading state while fetching, empty state if no data

## 4. Fix Graphs Not Showing (44.30.69.131)

### Root Causes

**a) Rate window too wide:** `rate(...[5m])` with 15s scrape intervals works but is sluggish. The recommended minimum rate window is 4× the scrape interval (60s). Using `[5m]` means each computed rate point averages over 20 scrapes, which can mask short bursts and produces a very smoothed signal.

**b) Integer rounding kills small values:** `(int) round((float) $point[1])` rounds sub-0.5 bytes/sec rates to 0. After ×8 bits conversion, a 0.3 bytes/sec rate = 2.4 bps would be lost.

**c) No bytes-to-bits conversion:** The `formatValue()` in TimeSeriesChart labels values as bps/Kbps/Mbps/Gbps, but the raw Prometheus `rate()` on byte counters returns bytes/sec. Values need ×8 multiplication.

### Fixes

**Rate window:** Change from `[5m]` to `[2m]` in all `rate()` calls in `PrometheusTrafficMonitor`. This is 8× the scrape interval — responsive but stable.

**Float pipeline:** Change `UserBandwidth` value object:
- `download` and `upload` arrays: `array<int, float>` instead of `array<int, int>` — store raw rate values as floats
- `received` and `sent` totals: keep as `int` (total bytes, summed from rate×step)

In `PrometheusTrafficMonitor::getUserBandwidth()`:
- Remove `(int) round()` from download/upload value extraction — keep as `(float)`
- Multiply each value by 8 to convert bytes/sec → bits/sec
- For totals: sum the bytes/sec values × step interval to approximate total bytes transferred, then ×8 for bits

**Frontend:** `formatValue()` in TimeSeriesChart already handles unit scaling correctly for bits. No frontend changes needed for unit display.

## 5. Prometheus Rate Parameters & Scaling

### Rate Window

All `rate()` calls in `PrometheusTrafficMonitor` use `[2m]` instead of `[5m]`:
- `getUserBandwidth()`: both in and out queries
- `getAggregateStats()`: both rcvd and sent bandwidth queries
- `getTopTalkers()`: rate query

### Step Resolution

Keep current logic (matches well with `[2m]` rate window):
- ≤1h range → 60s step
- ≤24h range → 300s step
- >24h range → 900s step

### Value Pipeline

```
Prometheus counter (bytes total)
  → rate()[2m] = bytes/sec (float)
  → × 8 = bits/sec (float)
  → frontend formatValue() = "1.2 Mbps"
```

### Aggregate Stats

`getAggregateStats()` total bandwidth: already uses `sum(rate(...))` — change rate window to `[2m]`, multiply result by 8 for bits/sec display.

`getTopTalkers()`: same rate window change, multiply by 8.
