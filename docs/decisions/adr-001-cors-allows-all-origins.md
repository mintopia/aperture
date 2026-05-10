# ADR 001: CORS Allows All Origins

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-006 identified that `config/cors.php` sets `allowed_origins` to `['*']`, allowing any origin to make cross-origin requests to the Aperture API. The captive portal API at `/api/captive-portal` is unauthenticated and IP-based, and `supports_credentials` is set to `false`, preventing cookie-based cross-origin attacks.

## Decision
Accept the current CORS configuration. The captive portal API implements RFC 8908 (Captive Portal API), which is designed to be queried by operating systems and browsers from any origin. Restricting CORS origins would break compliant clients that rely on cross-origin access to determine captive portal status. The `supports_credentials: false` setting prevents session cookies from being sent cross-origin, limiting the impact to the unauthenticated, IP-based captive portal endpoint.

## Consequences
- Any page served on the LAN can query the captive portal API to determine whether an IP has internet access.
- Combined with the TrustProxies fix (SEC-001), the information disclosed is limited to the requesting client's own IP status, which is acceptable per RFC 8908.
- If authenticated API endpoints are added in the future, CORS policy should be revisited to ensure they are not exposed to arbitrary origins.

## Supersedes
N/A
