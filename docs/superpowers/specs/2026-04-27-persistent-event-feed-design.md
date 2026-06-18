# Persistent Event Feed

## Overview

Replace the client-side-only EventFeed component with a persistent, server-backed event feed. Events are stored in the database, served via a dedicated admin page with filtering, and updated in real-time via WebSocket. A compact widget on the Dashboard preloads the last 10 events.

## Data Model

### SystemEvent Model

Table: `system_events`

| Column       | Type          | Notes                                              |
|-------------|---------------|-----------------------------------------------------|
| `id`        | bigIncrements | Primary key                                         |
| `type`      | string(100)   | Indexed. Short class name, e.g. `UserConnected`     |
| `level`     | string(20)    | Indexed. One of: `info`, `warning`, `critical`      |
| `message`   | text          | Human-readable description                          |
| `data`      | json, nullable| Raw event payload                                   |
| `created_at`| timestamp     | Indexed. No `updated_at` (events are immutable)     |

The model is named `SystemEvent` (not `Event`) to avoid namespace collision with `Illuminate\Support\Facades\Event`.

### Level Mapping

| Level      | Event Types                                                                       |
|-----------|------------------------------------------------------------------------------------|
| `info`     | UserConnected, DeviceDiscovered, SwitchSyncCompleted, InternetAccessChanged, RateLimitChanged, DnsFilterChanged |
| `warning`  | DhcpPoolThresholdReached, BandwidthAnomalyDetected                                |
| `critical` | SwitchUnreachable, UserBlocked                                                     |

### Message Formatting

Messages are generated server-side in the listener using the same logic as the current Vue `EVENT_FORMATTERS`. Examples:

- `alice connected from 10.0.0.42`
- `Switch core-sw-01 unreachable after 3 failures`
- `DHCP pool LAN reached 92% utilization`

## Backend Components

### RecordBroadcastEvent Subscriber

A queued `EventSubscriber` registered in `EventServiceProvider::$subscribe`. Uses a wildcard listener (`Event::listen('*', ...)`) that filters for `ShouldBroadcast` instances. Persists each event to the `system_events` table.

- Implements `ShouldQueue` with queue name `event-recording`
- Contains the message formatting logic (ported from Vue `EVENT_FORMATTERS`)
- Contains the level mapping logic
- Extracts `broadcastWith()` data for the `data` JSON column
- Uses the short class basename for the `type` column

### EventController

New controller: `App\Http\Controllers\Admin\EventController`

Single `index` method:

- Route: `GET /admin/events` (name: `admin.events.index`)
- Placed inside the existing `can:admin` middleware group
- Accepts query params: `search` (text filter), `perPage` (pagination)
- Queries `system_events` ordered by `created_at desc`
- Text search: `WHERE type LIKE ? OR message LIKE ?` with the search term
- Returns two counts: filtered total (from paginator) and unfiltered total (separate `count()` query)
- Renders `Admin/Events/Index` via Inertia with props: `events` (paginated), `filters`, `totalCount`, `breadcrumbs`

### Pruning Command

Artisan command: `events:prune`

- Deletes `system_events` where `created_at < now() - retention_days`
- Default retention: 30 days, configurable via `config('events.retention_days')`
- Uses chunked deletion (`DELETE ... LIMIT 1000` in a loop) to avoid long-running transactions
- Scheduled daily in `Console\Kernel`

### Dashboard Data

The `HomeController@index` (Dashboard) adds a deferred prop `recentEvents` containing the last 10 `SystemEvent` records, formatted as arrays with `id`, `type`, `level`, `message`, `created_at`.

## Frontend Components

### Event Feed Page — `Pages/Admin/Events/Index.vue`

Follows the Audit Log page pattern exactly:

- **Layout:** AdminLayout, page title "Event Feed" with subtitle "Real-time system activity stream"
- **Live indicator:** Green dot with "Live" text in header (when Echo is connected)
- **Filter:** Single text input using FilterBar pattern. Debounced (300ms) Inertia reload with `search` param. Shows "Showing {filtered} of {total} events" using both server counts.
- **Table:** DataTable with three columns:
  - **Time** — `created_at` formatted as relative time, mono font
  - **Event** — `type` displayed with color based on `level` (info=default, warning=warning color, critical=danger color)
  - **Details** — `message` text
- **Live events banner:** When WebSocket events arrive while viewing the page:
  - Events are collected in a client-side buffer
  - A banner appears: "N new events received — Reload" 
  - Clicking "Reload" triggers `router.reload()` to fetch fresh data from the server
  - This avoids race conditions between client-side live data and server-paginated data
  - The banner only counts events that match the current filter text (client-side match)
- **Pagination:** Standard `Pagination` component below the table
- **WebSocket:** Uses `useAdminChannel` composable (not a direct Echo subscription) to listen for all event types

### Dashboard EventFeed Widget — Refactored `Components/Admin/EventFeed.vue`

The widget is refactored to accept server-provided data:

- **Props:** `events` (Array, default []) — preloaded from DB, `maxEvents` (Number, default 50)
- **No direct WebSocket subscription.** The parent page (Dashboard.vue) already uses `useAdminChannel`. Dashboard maintains a reactive `eventFeedItems` array and passes it as a prop.
- **Dashboard.vue changes:**
  - Initializes `eventFeedItems` from `recentEvents` deferred prop (last 10 from DB)
  - Registers all event types in `useAdminChannel`'s `events` config — each handler formats the event and prepends to `eventFeedItems`
  - Caps `eventFeedItems` at `maxEvents` (50)
  - Passes `eventFeedItems` to `<EventFeed :events="eventFeedItems" />`
- **Display:** Same compact list format (timestamp + message), with a "View All →" link to `/admin/events`
- **Padding:** `mt-10` class added to the EventFeed section wrapper in Dashboard.vue (was directly adjacent to Recent Users)

### Sidebar Navigation

New entry in `Components/Admin/Sidebar.vue` under the SYSTEM group, alongside "Audit Log":

- Label: "Event Feed"
- href: `route('admin.events.index')`
- Icon: New `EventFeedIcon` component (activity/pulse style icon)

## Route

```
GET /admin/events → EventController@index → admin.events.index
```

Placed inside the existing admin middleware group in `routes/web.php`, near the Audit Log route.

## Testing

### PHP Tests

- **Unit:** `SystemEvent` model casts, factory states for each level
- **Feature:** `EventControllerTest` — index renders, search filter works, pagination works, authorization (non-admin denied)
- **Feature:** `RecordBroadcastEventTest` — listener persists events correctly for each event type, level mapping is correct, message formatting matches expected output
- **Feature:** `EventsPruneCommandTest` — deletes old events, respects retention config, chunked deletion

### JavaScript Tests

- **Vitest:** `Events/Index.spec.js` — renders table, filter input, count display, live event banner, pagination
- **Vitest:** `EventFeed.spec.js` — updated to test prop-driven behavior, View All link, event prepending
- **Vitest:** `Dashboard.spec.js` — updated to test EventFeed receives events prop

### Factory

`SystemEventFactory` with states: `info()`, `warning()`, `critical()`, and named states for each event type (e.g., `userConnected()`, `switchUnreachable()`).

## Configuration

New config file `config/events.php`:

```php
return [
    'retention_days' => env('EVENT_RETENTION_DAYS', 30),
];
```

## Migration Path

1. Create migration and model
2. Register subscriber — existing events start being persisted immediately
3. Deploy Event Feed page and sidebar link
4. Refactor Dashboard widget to use server data
5. The old client-side-only behavior is fully replaced
