# ADR 006: SSH Proxy Uses HTTP Transport

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-004 identified that `SshProxyClient` builds its proxy URL with a hardcoded `http://` scheme, transmitting switch credentials (hostname, username, password, port) and the proxy bearer token over plaintext HTTP. If the SSH proxy runs on a separate host this exposes credentials to network interception.

## Decision
Accept the current HTTP transport. The SSH proxy is a co-located sidecar service (see `ssh-proxy/` and `docker-compose.yaml`), running in the same Docker network as the application container. In the standard deployment topology, traffic between the application and the SSH proxy never traverses a shared or untrusted network segment — it is Docker bridge networking or localhost. Adding TLS between two services in the same Docker network would add operational complexity (certificate management) for no meaningful security gain in the intended deployment topology.

## Consequences
- Operators who deploy the SSH proxy on a separate host (outside Docker networking) will have switch credentials in cleartext on the wire between the app and proxy.
- This deployment topology is not the intended or documented configuration; operators deviating from it accept responsibility for the additional transport risk.
- If the SSH proxy is ever intended to support remote/external deployment, this decision must be revisited and TLS or mTLS added.
- Deployment documentation should document that the SSH proxy must be co-located with the application container.

## Supersedes
N/A
