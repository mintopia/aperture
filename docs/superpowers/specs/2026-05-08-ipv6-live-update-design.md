# IPv6 Live Update on Dashboard Detection

**Date:** 2026-05-08
**Scope:** Frontend-only, 2 files modified

## Problem

The `useIpv6Detection` composable calls `POST /ipv6` on mount and every 2 minutes. The endpoint returns `{ ip, internetEnabled }` but the response is discarded. The connection strip block displays `{ipv6}` from `liveContext.currentIpv6`, which is only set from the initial server-rendered page props. If IPv6 is detected after page load, the field stays empty until a full page refresh.

## Solution

Thread the POST response back through the composable via a callback, and update `liveContext` reactively.

### Changes

**`resources/js/composables/useIpv6Detection.js`**

- `detectAndSubmitIpv6(endpointTemplate)`: parse the JSON response from `POST /ipv6` and return `{ ip, internetEnabled }` on success, `null` on failure.
- `useIpv6Detection(endpoint, { onDetected })`: accept an options object with an `onDetected` callback. Call it with the response data whenever `detectAndSubmitIpv6` returns a non-null result. The second positional parameter `intervalMs` moves into the options object for a cleaner API.

**`resources/js/Pages/Portal/Dashboard.vue`**

- Pass `onDetected` to `useIpv6Detection` that sets:
  - `liveContext.currentIpv6 = data.ip`
  - `liveContext.internetEnabled = data.internetEnabled`

### No changes required

- Backend (`PortalController::ipv6`) already returns both fields.
- `ConnectionStripBlock.vue` and `renderTemplate` already read `currentIpv6` reactively from `blockContext`.
- No new dependencies, no new files.

## Testing

- Unit tests for `detectAndSubmitIpv6` returning parsed response.
- Unit tests for `useIpv6Detection` calling `onDetected` on success and not calling it on failure.
- Feature test confirming `liveContext` updates propagate to rendered connection strip output.
- Existing tests must continue to pass (composable is backward-compatible when no callback provided).
