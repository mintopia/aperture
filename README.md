# Aperture

## Introduction

Aperture is a Captive Portal system designed for LAN parties. The aim is to provide an easy way to allow users to authenticate, whilst also allowing network admins to easily identify and link IP addresses to users and provide quick tools for managing the network.

## Intended User Flow

1. User sets up computer
2. When accessing the internet, gets a prompt to login with a URL and device code (OAuth2 Device Auth) and a QR code for the URL and device code. The user needs to use their phone for this.
3. User uses their phone and authenticates at external provider (Borealis)
4. User is logged in when auth completes
5. User is shown interstitial while access is granted
6. Access is granted via queued asynchronous action
7. User is forwarded to dashboard
8. On dashboard user can view content, their network usage, IP address details
9. Dashboard allows user to opt-in to PiHole ad blocking at a network level

## Current Feature List

 - Authentication with Discord via 3rd Party Externally hosted application (Borealis)
 - Captive Portal support with IPv4 and IPv6
 - LANCache/DNS Detection and warning
 - Rate limiting
 - Blocking
 - Basic stats collection

## Intended Feature List

 - Basics
     - Authentication through OAuth2 Device Flow
     - Captive Portal integration with OpnSense
     - IPv4 and IPv6 Support
     - DNS Server Detection and Warning
 - User Dashboard
    - Opt-in to PiHole Ad Blocking
    - Graphs and Stats
    - Content and Information
    - Industry standard captive portal support
    - 5 day session time
    - Persisted allow state for outages/problems, automatic re-applying of rules
- Admin
    - Lookup IPs and Users
    - Block/Allow/Deny IPs and Users
    - User Searching, Viewing
    - User IP History
    - IP/User Rate Limiting
    - Statistics
    - Network Port for User/IP
    - Port Config
    - Port Controls (shut/unshut)
    - Working with Cisco Switches
    - Port Statistics and Errors
    - Automatic enabling for devices like XBoxes
    - Portal reset functionality (start of event)
    - DHCP Pool Status
    - Content editing
- Data Sources
    - SNMP (Network Switches, Firewall/Router, Port Status and Stats)
    - OpnSense API (Control over Captive Portal, DHCP)
    - NtopNG (User Bandwidth)
    - LibreNMS (FDB Table, IP to Port Mapping - could obtain from SNMP)
- Social Auth Providers
    - Provided by remote auth service (Borealis), so out of scope
- Design Paradigms
    - Modular, if we can have other sources for information, have them configurable and swappable
    - Easy to use
    - Quick to admin
    - No CDN usage, everything has to be served locally
    - Extendable - easy to add custom modules and content panels, functionality

## Current Issues

The current implementation is an MVP that works, but has some issues:

 - User flow is not clear on authentication, and responsibilities are mixed. Ultimately Aperture doesn't need to know about Discord or any social auth provider, it should treat Borealis as its auth provider (or potentially any system supporting Device Flow auth).
 - Connecting to the switch is slow, we should have an SSH service that caches connections to network switches and then exposes a REST API for executing commands, with an expect/conditional type of language, based on the last response:

   - Request is a series of commands with optional conditions to trigger them
   - Conditions can be based on regex/wildcard/text of the last prompt

  This is required to allow us to enter/leave enable mode as required for instance.

## Key Notes

## Local Dev Environment (Traefik / 443)

Use the root scripts to run the full stack (app, Vite HMR, Reverb, SSH proxy, Horizon, scheduler):

```bash
./dev-start.sh
./dev-health.sh
./dev-status.sh
./dev-restart.sh
./dev-stop.sh
```

- `./dev-restart.sh` runs stop then start using the same `DEV_START_MODE` / Traefik auto-detection flow.
- `./dev-health.sh` checks hostname reachability for app/reverb/vite in compose mode, and checks process liveness + local endpoints in no-compose local mode.
- `./dev-status.sh` shows a concise table of dev service state/source, pid, endpoint, and quick health.

Expected HTTPS hostnames (defaults; override with `PUBLIC_APP_HOSTNAME`, `PUBLIC_REVERB_HOSTNAME`, `PUBLIC_VITE_HOSTNAME` in `.env`):

- https://hallowed-rincewind.ws.cloudagent.mintopia.net
- https://reverb.hallowed-rincewind.ws.cloudagent.mintopia.net
- https://vite.hallowed-rincewind.ws.cloudagent.mintopia.net
- https://ssh-proxy.hallowed-rincewind.ws.cloudagent.mintopia.net

`./dev-start.sh` now auto-detects Traefik:

- Traefik running: starts HTTPS hostname mode
- Traefik missing: falls back to local ports (`http://127.0.0.1:8000`, `:5173`, `:8080`)
- Compose unavailable: automatically falls back to local process mode (no docker required)

You can force mode with `DEV_START_MODE=traefik` or `DEV_START_MODE=ports`.

### No-compose local process mode

When docker compose is unavailable (or docker engine is not reachable), the scripts run these services directly on the host and manage PID/log files under `.dev-env/`:

- `aperture`: `php artisan serve` (default `127.0.0.1:8000`)
- `reverb`: `php artisan reverb:start` (default `127.0.0.1:8080`)
- `ssh-proxy`: `go run ./ssh-proxy` (default `127.0.0.1:8022`)
- `horizon`: `php artisan horizon`
- `scheduler`: `php artisan schedule:work`
- `vite`: `npm run dev` (default `127.0.0.1:5173`)

Useful local overrides:

- `DEV_APP_HOST`, `DEV_APP_PORT`
- `DEV_REVERB_HOST`, `DEV_REVERB_PORT`
- `DEV_VITE_HOST`, `DEV_VITE_PORT`
- `DEV_SSH_PROXY_HOST`, `DEV_SSH_PROXY_PORT`
- `DEV_STOP_TIMEOUT` (seconds before force-kill on stop)

## Local CSS/JS

All HTTP content served on the Captive Portal needs to be hosted locally as the user will not have Internet access and so will not be able to use resources from a CDN.

### FDB and ARP Update

We need to force LibreNMS to update the FDB and ARP table in its database. Schedule this using cron for every 5 minutes:

```
docker compose exec librenms php discovery.php -h <switch ip> -m fdb-table,arp-table
```
