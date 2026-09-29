# ADR 002: SSRF via Admin-Configurable JWKS URL

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-009 identified that the JWKS URL used by `Ipv6JwtService` is admin-configurable and fetched server-side without restricting internal/private IP ranges. An admin could configure the URL to point at internal services, enabling server-side request forgery from the application server's network perspective.

## Decision
Accept the risk. The JWKS URL is only configurable by authenticated administrators, who already have extensive control over the system including firewall rules, switch management, and integration credentials. The marginal additional capability provided by SSRF does not meaningfully expand an admin's existing access. The 10-second timeout and 1-hour response cache further limit the utility of this vector.

## Consequences
- An admin could theoretically use the JWKS URL to probe internal services not otherwise accessible to them.
- The risk is mitigated by the narrow pool of users who can configure this setting and the limited response data returned (JSON parse errors for non-JWKS responses).
- If the admin role is expanded to less-trusted users in the future, this decision should be revisited.

## Supersedes
N/A
