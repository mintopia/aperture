# Aperture

A captive portal system designed for LAN parties. Aperture provides an easy way to authenticate users whilst allowing network administrators to identify and link IP addresses to users, with tools for managing the network.

## Features

- **Authentication** — OAuth2 Device Flow via external provider (Borealis), supporting Discord and other social providers
- **Captive Portal** — IPv4 and IPv6 support with OpnSense integration and industry-standard captive portal detection
- **User Dashboard** — Opt-in to PiHole ad blocking, bandwidth graphs, content pages, connection status
- **Admin Panel** — User/IP lookup, blocking, rate limiting, DHCP pool status, content editing, event feed
- **Switch Management** — Cisco switch integration via SSH proxy, port controls (shut/unshut), FDB/ARP tables, port statistics
- **DNS Detection** — LANCache/DNS server detection and user warnings
- **Real-time Events** — WebSocket-driven event feed via Laravel Reverb

## User Flow

1. User connects to the network
2. Captive portal presents a URL, QR code, and device code for OAuth2 Device Flow authentication
3. User authenticates on their phone via the external provider
4. Access is granted asynchronously and the user is redirected to their dashboard
5. Dashboard shows content, network usage, IP details, and ad blocking opt-in

## Tech Stack

- **Backend** — PHP 8.4, Laravel 13, FrankenPHP/Octane
- **Frontend** — Vue 3, Inertia.js, Tailwind CSS 4
- **Real-time** — Laravel Reverb (WebSockets)
- **Queue** — Laravel Horizon (Redis)
- **SSH Proxy** — Go service for switch communication
- **Infrastructure** — Docker, multi-platform (amd64/arm64)

## Local Development

Use the scripts in `bin/` to manage the full dev stack (app, Vite HMR, Reverb, SSH proxy, Horizon, scheduler):

```bash
bin/dev-start.sh      # Start all services
bin/dev-stop.sh       # Stop all services
bin/dev-restart.sh    # Restart all services
bin/dev-health.sh     # Check service health
bin/dev-status.sh     # Show service status table
```

`bin/dev-start.sh` auto-detects the environment:

- **Traefik running** — starts with HTTPS hostnames
- **Traefik missing** — falls back to local ports (`http://127.0.0.1:8000`, `:5173`, `:8080`)
- **Docker unavailable** — runs services directly on the host, managing PIDs under `.dev-env/`

Force a mode with `DEV_START_MODE=traefik` or `DEV_START_MODE=ports`.

## Quality Checks

```bash
bin/quality.sh        # Run all checks
composer quality      # Same via Composer
```

Individual commands:

```bash
# PHP
vendor/bin/pint --format agent          # Formatting
vendor/bin/phpstan analyse              # Static analysis (level 8)
vendor/bin/rector process --dry-run     # Code quality
php artisan test --compact              # Tests

# JavaScript
npm run lint                            # ESLint
npm run format:check                    # Prettier
npm run test:coverage                   # Vitest + coverage
npm run e2e                             # Playwright E2E
```

## CI/CD

GitHub Actions runs parallel PHP and JS quality gates on every push and PR. Docker images are built and pushed to GHCR on merge to `master` or `develop`, and on version tags.

## Licence

This project is licensed under the MIT Licence. See [LICENSE](LICENSE) for details.
