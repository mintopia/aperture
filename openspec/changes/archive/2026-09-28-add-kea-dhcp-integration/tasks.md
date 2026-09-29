# Tasks

Epic: mintopia/aperture#3. Each section is one GitHub ticket; blockers are native GitHub issue dependencies.

## 1. Kea Integration with IPv4 Endpoint and connection test (#4)
- [x] 1.1 Register Kea Integration offering DHCP and IP-MAC Capabilities
- [x] 1.2 IPv4 Endpoint config, optional paired credentials, `verify_ssl`
- [x] 1.3 Kea client: command envelope with `service`, Basic auth, TLS, result codes
- [x] 1.4 Connection test via `list-commands` with per-reason failures
- [x] 1.5 Playwright: configure and test Kea (IPv4)

## 2. Sync IPv4 DHCP Leases (#5) — blocked by #4
- [x] 2.1 Paged `lease4-get-page` until empty
- [x] 2.2 Active-lease filter; map IP, MAC, hostname, expiry

## 3. Sync IPv4 DHCP Ranges and Pool Status (#6) — blocked by #5
- [x] 3.1 Parse `config-get` pools incl. shared networks, range and CIDR forms
- [x] 3.2 Range label fallback
- [x] 3.3 Computed Pool Status

## 4. Live IPv4 IP-MAC Lookup (#7) — blocked by #4
- [x] 4.1 `lease4-get` by address with active filter; graceful errors

## 5. Kea as IP-MAC Capability provider (#8) — blocked by #5
- [x] 5.1 IP-MAC Table from active IPv4 leases with MACs

## 6. IPv6 Endpoint configuration and connection test (#9) — blocked by #4
- [x] 6.1 IPv6 Endpoint config with own credentials; at-least-one validation
- [x] 6.2 Per-Endpoint connection test results
- [x] 6.3 Playwright: IPv6-only and dual-Endpoint

## 7. Sync IPv6 DHCP Leases with independent Address Family failure (#10) — blocked by #5, #9
- [x] 7.1 Paged `lease6-get-page`
- [x] 7.2 IPv6 MAC derivation (hw-address, DUID-LLT/LL)
- [x] 7.3 Per-family sync and failure isolation

## 8. Sync IPv6 DHCP Ranges and Pool Status (#11) — blocked by #6, #10
- [x] 8.1 IPv6 pools, `pd-pools` ignored
- [x] 8.2 Capped IPv6 Pool Status totals

## 9. IPv6 in live IP-MAC Lookup and IP-MAC Table (#12) — blocked by #7, #8, #10
- [x] 9.1 `lease6-get` (`IA_NA`); unconfigured family returns no lease
- [x] 9.2 IPv6 entries in IP-MAC Table
