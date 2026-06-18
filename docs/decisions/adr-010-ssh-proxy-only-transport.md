# ADR 010: SSH Proxy Is the Only Switch Transport

Status: Accepted
Date: 2026-06-10

## Context
The application previously supported two switch command transports: `SshProxyTransport` (HTTP calls to the co-located ssh-proxy sidecar, see ADR 006) and `DirectSshTransport` (direct SSH from the application container using phpseclib), selected at runtime by the `aperture.ssh_proxy.enabled` config flag (`APERTURE_SSH_PROXY_ENABLED`). The project owner has directed, repeatedly, that the SSH proxy sidecar is the only sanctioned way to talk to switches and that no fallback path may remain. Beyond the directive, the direct path kept switch credentials and SSH session handling inside the application's process and network path, duplicating responsibility that the sidecar exists to concentrate.

## Decision
`DirectSshTransport` is removed from the codebase. `SwitchServiceFactory` always builds an `SshProxyTransport`; if no `SshProxyClientInterface` is available it throws a `RuntimeException` — there is no silent fallback. The `aperture.ssh_proxy.enabled` config key and `APERTURE_SSH_PROXY_ENABLED` environment variable are removed: the proxy is mandatory, not toggleable. The `phpseclib/phpseclib` dependency is removed from the application's `composer.json` (the sidecar is a separate Go service and does not use it). Direct SSH from the application container to switches is prohibited.

## Consequences
- The application cannot reach switches without the ssh-proxy sidecar; local development environments must run it.
- `APERTURE_SSH_PROXY_ENABLED` no longer exists; any deployment still setting it has no effect, and `APERTURE_SSH_PROXY_HOST`/`PORT`/`API_KEY` must be configured.
- Switch credentials are no longer used to open SSH sessions from the app process; all SSH handling is concentrated in the sidecar.
- One fewer PHP dependency (phpseclib) in the application image.

## Supersedes
N/A (complements ADR 006)
