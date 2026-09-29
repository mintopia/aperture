# Aperture

A captive portal system designed for LAN parties. Aperture provides an easy way to authenticate users whilst allowing network administrators to identify and link IP addresses to users, with tools for managing the network.

## Features

- **Authentication** — OAuth2 Device Flow via external provider (Borealis), supporting Discord and other social providers
- **Captive Portal** — IPv4 and IPv6 support with OpnSense integration and industry-standard captive portal detection
- **User Dashboard** — Opt-in to PiHole ad blocking, bandwidth graphs, content pages, connection status
- **Admin Panel** — User/IP lookup, blocking, rate limiting, DHCP pool status, content editing, audit log
- **Switch Management** — Cisco switch integration via SSH proxy, port controls (shut/unshut), FDB/ARP tables, port statistics
- **DNS Detection** — LANCache/DNS server detection and user warnings
- **Real-time Events** — WebSocket-driven live updates via Laravel Reverb

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

Thin wrappers over `docker compose` manage the full dev stack (app, Vite HMR, Reverb, SSH proxy, Horizon, scheduler):

```bash
bin/dev-start.sh      # docker compose up -d --build --wait
bin/dev-stop.sh       # docker compose down
bin/dev-restart.sh    # down, then up
bin/dev-status.sh     # docker compose ps (includes health)
```

Health comes from the compose healthchecks; `--wait` blocks until they pass. Traefik mode (HTTPS hostnames) is used when a `traefik` container is running, otherwise local ports (`http://127.0.0.1:8000`, `:5173`, `:8080`). Force one with `DEV_START_MODE=traefik` or `DEV_START_MODE=ports`.

Run one-off tools with `docker compose run --rm`. The `aperture` image's entrypoint starts Octane, so override it with `--entrypoint`:

```bash
docker compose run --rm --entrypoint php aperture artisan tinker
docker compose run --rm --entrypoint composer aperture install
docker compose run --rm --entrypoint php aperture vendor/bin/phpunit
docker compose run --rm vite npm install
```

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

The production image runs the app as `PUID`/`PGID` (default `82`/`82`, Alpine's `www-data`). Set them to match the owner of your mounted volumes; on start the entrypoint re-owns `/app/storage`, `/app/bootstrap/cache`, `/data/caddy` and `/config/caddy` to that UID/GID, then drops root. If you start the container with `user:` instead, the entrypoint runs as that user and skips the ownership step.

In `docker-compose.yaml`, the `db` (MariaDB) and `redis` services also run as `${PUID:-999}:${PGID:-999}` via `user:`. Those upstream images don't re-own their data directories, so when you change `PUID`/`PGID`, `chown -R` the existing MariaDB volume to match first.

## Licence

This project is licensed under the MIT Licence. See [LICENSE](LICENSE) for details.
