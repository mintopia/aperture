# ADR 005: SSRF via Integration Test Endpoints

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-003 identified that `TestConnectionController` and `ConnectionTester` accept admin-supplied integration configuration URLs, make outbound HTTP requests to them server-side, and return or persist raw response bodies. In the event LAN environment the application server has privileged access to management interfaces, switches, and internal services, so this could be used to probe those from within the server's network context. This is a broader SSRF surface than ADR-002 (which covers only the JWKS URL in `Ipv6JwtService`).

## Decision
Accept the risk. The integration test endpoints are accessible only to authenticated users with the `admin` role. Administrators in Aperture already have extensive privileged access: they can configure firewall rules, manage switches via SSH, control DHCP, and modify network policy. The marginal capability provided by SSRF via integration test endpoints does not materially expand what a legitimate admin can already do, and does not provide meaningful capability to a compromised admin account beyond what they already have.

The response bodies returned are used to display connection test results to the admin and stored in `ConnectionTestLog` for diagnostics. Restricting or redacting these would reduce the usefulness of the connection testing feature for its primary purpose.

## Consequences
- An admin could use integration test endpoints to probe internal services not otherwise accessible from a browser.
- The risk is bounded by the admin role requirement and the existing extensive privilege set of admin users.
- If the admin role is ever granted to less-trusted users, or if non-admin users gain access to integration settings, this decision must be revisited.
- The `ConnectionTestLog` persists response bodies; this should be considered when evaluating log retention and access control.

## Supersedes
N/A

## Related
ADR-002 covers a related but narrower SSRF vector (JWKS URL in `Ipv6JwtService`).
