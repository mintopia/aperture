# Change: Add Kea DHCP Integration

## Why

Sites running ISC Kea as their DHCP server directly (not behind OPNsense) have no way to feed DHCP Leases, DHCP Ranges, Pool Status or IP-MAC data into Aperture. The only Kea support today is the OPNsense adapter's Kea mode, which depends on OPNsense's own API wrappers.

## What Changes

- New standalone **Kea** Integration talking to Kea's native control API.
- Two independent, optional Endpoints — IPv4 (`kea-dhcp4`) and IPv6 (`kea-dhcp6`) — at least one required, each with optional HTTP Basic credentials; one shared `verify_ssl` toggle.
- Kea can hold the **DHCP** Capability: DHCP Leases (via `lease_cmds` paging), DHCP Ranges (via `config-get`), computed Pool Status, and live IP-MAC Lookup by address.
- Kea can hold the **IP-MAC** Capability: an IP-MAC Table derived from active DHCP Leases.
- Each Address Family syncs and fails independently.
- Connection test verifies each configured Endpoint and the presence of required commands.
- First Integration with more than one Endpoint (no ADR — deliberately not recorded).

## Impact

- Affected specs: `kea-integration` (new capability spec)
- Affected code: Integration enum and registration, integration config schema, DHCP and IP-MAC service bindings, connection testers, admin integration config form
- Tracking: epic mintopia/aperture#3, tickets #4–#12
- No schema changes expected: existing DHCP lease/range/pool-status/sync-state storage already distinguishes Address Family
