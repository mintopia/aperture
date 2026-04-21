# DNS Detection Feature

## Purpose

At a LAN party, users should be warned if their device is not using the event DNS servers. This matters because the event DNS provides LANCache — a game caching solution that dramatically speeds up game downloads. Without the correct DNS, users hit the public internet directly and miss the cache.

The feature is optional. When not configured, the dashboard behaves as if all users pass the check.

## How It Works

1. An admin configures a **check URL** containing a `{uuid}` placeholder and a custom **warning message** via the DNS Detection settings page.
2. When a user loads the portal dashboard, the browser replaces `{uuid}` with a random UUID (cache-buster) and fetches the URL.
3. The remote server responds with JSON:
   - `{"server": "event"}` — user is on event DNS. No warning.
   - `{"server": "online"}` — user is on public DNS. Show warning.
4. On failure (network error, timeout, malformed JSON) — treat as pass, no warning shown.
5. On fail ("online"), re-check every 60 seconds automatically. A manual refresh icon is also available.

Example check URL: `https://{uuid}.lancache.test.entropylan.party`

The key insight is that this **must** be a client-side fetch. The browser's DNS resolution determines whether the user reaches the event server or the public one. A server-side proxy would always use the server's DNS, defeating the purpose.

## Admin Settings

### Controller

New `DnsDetectionSettingsController` following the same pattern as `EventSettingsController` and `PortalSettingsController`.

- **Routes**: `GET /admin/settings/dns-detection` and `PUT /admin/settings/dns-detection`
- **Route names**: `admin.settings.dns-detection` and `admin.settings.dns-detection.update`

### Settings (stored in `settings` table)

| Code | Name | Type | Validation |
|------|------|------|------------|
| `dns.check_url` | DNS Check URL | string, nullable | Must be a valid URL containing `{uuid}` when provided |
| `dns.warning_message` | DNS Warning Message | string, nullable | Max 500 chars. Defaults to "Your device is not using the event DNS servers. Please update your DNS settings." |

When `dns.check_url` is empty/null, the feature is disabled.

### Admin Page

`Admin/Settings/DnsDetection.vue` — two fields:
- Text input for the check URL (with placeholder showing the `{uuid}` format)
- Textarea for the warning message

The "DNS Detection" item in `SettingsNav.vue` changes from disabled (with "Soon" badge) to an active link pointing to `admin.settings.dns-detection`.

## Dashboard Data Flow

The `DashboardController` reads `dns.check_url` and `dns.warning_message` from the `Setting` model and passes them to the frontend:

```php
'dnsDetection' => $checkUrl ? [
    'checkUrl' => $checkUrl,
    'warningMessage' => $warningMessage ?? 'Your device is not using the event DNS servers. Please update your DNS settings.',
] : null,
```

When `dns.check_url` is null/empty, `dnsDetection` is `null` and the frontend knows the feature is off.

## Frontend Component

### DnsWarningBlock.vue

Complete rewrite of the existing component. New prop interface:

| Prop | Type | Description |
|------|------|-------------|
| `checkUrl` | String | URL with `{uuid}` placeholder |
| `warningMessage` | String | Admin-configured warning text |

**Lifecycle:**

1. On mount: if `checkUrl` is present, perform the DNS check
2. Replace `{uuid}` in the URL with `crypto.randomUUID()`
3. Fetch the URL, parse JSON response
4. `server: "event"` = pass (hide warning, stop checking)
5. `server: "online"` = fail (show warning, start 60s retry interval)
6. Any error = treat as pass
7. On unmount: clear interval timer

**Display when failing:**
- Warning banner with icon (matching current warning styling)
- Admin-configured warning message
- Clickable refresh icon for manual re-check

### Dashboard.vue

Replace the content-block-based DNS warning with the settings-driven approach:

- Accept `dnsDetection` prop (object or null)
- Render `DnsWarningBlock` only when `dnsDetection` is non-null
- Pass `checkUrl` and `warningMessage` as props

## Cleanup

These existing artifacts reference the old DNS approach and need updating:

- `test_dns_config_removed` in `ApertureConfigTest.php` — remove (guarded against `config('aperture.dns')` which is no longer relevant)
- `DnsWarningBlock.spec.js` — replace entirely (old props `hasDnsIssue`, `expectedDns`, `actualDns` no longer exist)
- `test_dns_warning_block_passes_settings_with_expected_dns` in `DashboardControllerTest.php` — replace with test for new `dnsDetection` prop shape
- `test_portal_dns_warning_uses_hidden_class` in `PortalControllerTest.php` — replace with test for new behavior

## Testing

### PHP (PHPUnit)

**DnsDetectionSettingsControllerTest:**
- Settings page loads with current settings
- Update validates and saves both fields
- Empty URL clears the setting (disables feature)
- URL without `{uuid}` placeholder is rejected
- Warning message respects 500 char limit

**DashboardControllerTest:**
- `dnsDetection` prop contains `checkUrl` and `warningMessage` when `dns.check_url` is configured
- `dnsDetection` prop is `null` when no URL configured
- Default warning message used when `dns.warning_message` is null

### JavaScript (Vitest)

**DnsWarningBlock.spec.js:**
- Renders nothing when no `checkUrl` provided
- Shows warning when fetch returns `{"server": "online"}`
- Hides when fetch returns `{"server": "event"}`
- Treats fetch errors (network, bad JSON) as pass
- Retry timer fires every 60s after failure
- Manual refresh icon triggers immediate re-check
- Clears interval on unmount
- Replaces `{uuid}` in URL with a valid UUID

**DnsDetection.spec.js:**
- Admin settings page renders URL and message fields
- Form submission sends correct data

**SettingsNav.spec.js:**
- DNS Detection is now an active link, not disabled

### Playwright

- Admin configures DNS detection URL and message via settings page
- Dashboard renders warning when check fails (using `https://{uuid}.lancache.test.entropylan.party` or a mock)
- Dashboard hides warning when check passes
