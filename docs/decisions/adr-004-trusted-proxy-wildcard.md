# ADR 004: Trusted Proxy Defaults to Wildcard

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-001 identified that `TrustProxies` middleware falls back to trusting `*` (all sources) when `TRUSTED_PROXY_IPS` is not set. This makes `getClientIp()` spoofable via forged `X-Forwarded-For` headers, affecting rate limiting, captive portal IP binding, audit logs, and IP ownership association.

## Decision
Accept the current wildcard default. Aperture is designed as a LAN-deployed event network management tool, typically running behind a single reverse proxy (Traefik, Caddy, or nginx) within a controlled physical environment. Enforcing an explicit `TRUSTED_PROXY_IPS` configuration would break out-of-the-box deployments for the primary use case (events with a single ingress proxy) without meaningful security gain in that context.

The risk is documented and operators deploying in environments with untrusted LAN clients should set `TRUSTED_PROXY_IPS` explicitly in their `.env`. This requirement will be added to the deployment documentation.

## Consequences
- Any LAN client can spoof `X-Forwarded-For` and affect rate-limiting, captive portal IP binding, and audit records in deployments that do not override `TRUSTED_PROXY_IPS`.
- Operators in high-trust environments (single physical LAN, controlled access) accept this risk as part of the deployment context.
- Deployment documentation must warn about this behaviour and recommend setting `TRUSTED_PROXY_IPS` explicitly for sensitive deployments.
- If Aperture is ever deployed in a multi-tenant or internet-facing configuration this decision must be revisited.

## Supersedes
N/A
