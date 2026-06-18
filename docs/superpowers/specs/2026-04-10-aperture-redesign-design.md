# Aperture Redesign — Design Specification

**Date**: 2026-04-10
**Status**: Approved

## Overview

Aperture is a captive portal system for LAN parties. This spec covers a full UI/UX redesign and implementation of missing features, transitioning from the current Blade + Tabler MVP to a modern Vue 3 + Inertia.js architecture with Tailwind CSS.

## Architecture

### Three-Layer Frontend

1. **Captive Portal** (Blade + Vanilla JS)
   - Served to users with no internet access
   - All assets local (no CDN dependencies)
   - Shows QR code + device code for OAuth2 Device Flow
   - Vanilla JS polls auth endpoint for completion
   - On auth success: shows interstitial → queued job grants access → redirects to user portal
   - Minimal, fast-loading, works on any device

2. **User Portal** (Vue 3 + Inertia.js)
   - Gaming-friendly, energetic design (Discord/Steam inspired)
   - Dashboard with draggable content blocks
   - Content blocks include: network stats, event information (markdown), PiHole ad-blocking toggle, IP/connection details, bandwidth usage graph
   - Session management is backend-only (invisible to user)
   - Persisted allow state survives outages with automatic re-application

3. **Admin Panel** (Vue 3 + Inertia.js)
   - Professional SaaS-style design (Linear/Vercel inspired)
   - Global search bar (users, IPs, ports)
   - User management: view, search, block, allow, rate limit
   - IP management: history, lookup, port mapping
   - Switch port controls: shut/unshut, statistics, errors
   - DHCP pool status
   - Statistics dashboard
   - Content block editor (markdown, draggable arrangement)
   - Theme customiser (pick bundled theme or customise colours)
   - Integration settings (all configured in admin UI, not .env)
   - Portal reset functionality (start of event)

### Tech Stack

- **Backend**: Laravel 12 (PHP 8.5), existing Laravel 10 structure
- **Frontend Framework**: Vue 3 + Inertia.js (hybrid — Vue components within Blade where needed)
- **CSS**: Tailwind CSS (replacing Tabler UI)
- **Captive Portal**: Blade + vanilla JS (no framework dependencies)
- **Build**: Vite
- **Testing**: PHPUnit
- **Queue**: Laravel Horizon

## Theming System

### CSS Custom Properties

All colours defined as CSS custom properties on `:root`. Tailwind configured to reference these properties.

### Bundled Themes

4 default themes, each with light and dark variants:
1. **Cool Neon** — Blues, cyans, electric accents
2. **Warm Neon** — Pinks, magentas, purple highlights  
3. **Matrix** — Greens, limes, teal undertones
4. **Amber Glow** — Oranges, ambers, warm tones

### Admin Customisation

- Admin UI allows selecting a bundled theme or creating custom colours
- Theme settings stored in DB (Settings model)
- Applied via middleware that injects theme CSS properties into the page
- Event branding: admins can upload logo, set event name, customise colours

## Authentication Flow

### OAuth2 Device Flow

1. User connects device to LAN network
2. Captive portal intercepts HTTP traffic, serves login page
3. Login page displays: QR code (URL + device code) and text device code
4. User scans QR with phone (phone has internet via mobile data)
5. Phone opens Borealis (or compatible OAuth2 Device Flow provider)
6. User authenticates with their identity provider (Discord, etc. — handled by Borealis)
7. Captive portal polls device auth endpoint via vanilla JS
8. On approval: captive portal shows interstitial ("Granting access...")
9. Backend queues job to grant access (OPNsense captive portal API, firewall rules)
10. On job completion: user redirected to user portal dashboard

### Session Management

- 5-day session duration (backend config, not exposed to user)
- Persisted allow state — if access rules are lost (outage/reboot), system automatically re-applies
- Session state tracked in DB

## Data Sources & Integrations

### Architecture

Each integration implements a PHP interface, making them swappable and extensible (e.g., support Juniper, VyOS alongside Cisco).

### Interfaces

- **CaptivePortalInterface** — Grant/revoke access, list sessions (impl: OPNsense)
- **NetworkSwitchInterface** — Port status, shut/unshut, FDB table, port stats (impl: Cisco via SSH)
- **DhcpInterface** — Pool status, lease information (impl: OPNsense)
- **TrafficMonitorInterface** — User bandwidth, usage stats (impl: ntopng)
- **NetworkInventoryInterface** — FDB table, ARP table, IP-to-port mapping (impl: LibreNMS)
- **AuthProviderInterface** — Device flow auth, user info (impl: Borealis)

### Configuration

- All integration credentials and settings configurable through admin UI
- Stored in DB via Settings model (encrypted where appropriate)
- .env reserved for app infrastructure only (DB, Redis, cache, app key)
- Admin can enable/disable integrations individually

### SSH Connection Caching

- Dedicated SSH daemon service caches connections to network switches
- Exposes REST API for executing commands
- Supports expect/conditional command sequences (regex/wildcard matching on responses)
- Required for efficient enable mode transitions on Cisco equipment

## Admin Panel Features

### Global Search
- Unified search bar searching across users, IP addresses, MAC addresses, switch ports
- Instant results as you type
- Keyboard shortcut to focus (Cmd/Ctrl+K)

### User Management
- List, search, filter users
- View user detail: auth info, IP history, current connection, bandwidth usage
- Actions: block, allow, rate limit
- Bulk operations support

### IP/Network Management
- IP address lookup with full history
- IP-to-user mapping
- IP-to-port mapping (via LibreNMS/SNMP)
- Rate limiting per IP

### Switch Port Management
- View all ports with status (up/down, speed, errors)
- Shut/unshut ports
- Port statistics and error counters
- Automatic Xbox/console detection and enabling
- Network port identification for user/IP

### DHCP Management
- Pool utilisation status
- Lease information

### Statistics Dashboard
- Network-wide bandwidth usage
- User count and connection stats
- Port utilisation
- Historical graphs

### Content Management
- Markdown-based content blocks
- Drag-and-drop arrangement for user dashboard
- Preview capability

### Settings
- Integration configuration (per-integration settings forms)
- Theme selection and customisation
- Event settings (name, logo, dates)
- Portal reset (clear all sessions, re-initialise for new event)

## User Portal Features

### Dashboard
- Draggable content blocks arranged by admin (user can rearrange)
- Available blocks:
  - **Event Info**: Markdown content from admin
  - **Connection Status**: Current IP, connection details, DNS server info
  - **Bandwidth Usage**: Graph of personal bandwidth over time
  - **Network Stats**: Aggregate network statistics
  - **PiHole Toggle**: Opt-in/out of network-level ad blocking
  - **DNS Warning**: Alert if incorrect DNS server detected (LAN cache issue)

### Interstitial
- Shown after auth while access is being granted
- Animated progress indicator
- Auto-redirects to dashboard on completion

## Testing Strategy

- PHPUnit feature tests for all controllers
- Unit tests for each service interface implementation
- Factory-based model creation for all tests
- Integration tests for auth flow
- Tests for theme system
- Tests for content block management

## Error Handling

- Captive portal gracefully handles auth polling failures
- Admin actions show clear success/failure feedback
- Integration failures (switch unreachable, API down) shown with clear status indicators
- Queued jobs have retry logic for transient failures
