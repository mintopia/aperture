# Switch Port Show Page Layout Design

## Problem
The current switch port details page needs a clearer operational hierarchy for real-time triage and monitoring, while reducing redundant controls and aligning with admin workflow preferences.

## Goals
1. Keep top actions minimal and high confidence: `Refresh` + `Shut/Unshut` only.
2. Prioritize fast troubleshooting and monitoring at first glance.
3. Remove duplicate status display from the header (status remains in metadata strip).
4. Maintain safe destructive workflows with explicit confirmation.
5. Preserve mobile usability with stacked layout.

## Evaluated Approaches

### Approach A (Selected)
- Action-first header with minimal controls.
- Two-column desktop layout with wider left column (`2fr / 1fr`).
- Left: connected devices, then running config.
- Right: bandwidth graph, error graph, then `show interface` output.
- Mobile: single-column stack.

**Why selected:** best matches operator flow and user-requested information priority.

### Approach B
- Same structure as A but denser telemetry-focused right rail.

**Trade-off:** better data density, worse scanability and readability under pressure.

### Approach C
- Strongly carded sections with heavier visual framing.

**Trade-off:** clearer grouping but adds visual weight/noise vs lean operational view.

## Approved Design

### 1. Architecture & Layout
- Header shows port identity and only:
  - `Refresh`
  - `Shut/Unshut` (state-aware)
- Status is not duplicated in header; it is represented in metadata strip below.
- Desktop content grid:
  - Left column (`2fr`): Connected Devices → Running Config
  - Right column (`1fr`): Bandwidth → Errors → Show Interface
- Mobile (`<= 1024px`): stack to single column in the same order.

### 2. Interaction & Safety
- `Refresh` updates dynamic sections (port, devices, metrics) and preserves context.
- `Shut/Unshut` is state-aware and always requires confirmation.
- Confirmation modal includes connected-device impact summary.
- Action buttons show loading and disabled states while requests are in flight.
- On success, page state refreshes in place (no disruptive navigation).

### 3. Data / Empty / Error States
- Telemetry cards support loading, empty, and unavailable states.
- Metrics backend unavailable is shown with explicit non-blocking messaging.
- Connected devices empty state: “No devices connected”.
- Show Interface empty state: “No interface output available”.
- Action failures surface inline feedback while preserving page context.

### 4. Testing Expectations
- Update Vue tests for:
  - action area simplification (no bounce action, no header status duplication)
  - state-aware `Shut/Unshut` labeling
  - always-on confirmation for shutdown state changes
- Keep/add assertions for desktop hierarchy and mobile stacking behavior.
- Keep section presence and `data-testid` alignment for telemetry/output ordering.

## Notes
- This page intentionally uses a split detail layout as an exception to default single-column detail patterns, because the operational workflow benefits from persistent side-by-side telemetry.
