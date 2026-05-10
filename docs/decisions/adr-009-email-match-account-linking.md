# ADR 009: Email-Match Account Linking in Device Flow

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-002 identified that `DeviceFlowUserService` falls back from matching on `external_id` to matching on email address, immediately linking the external identity to the local account. A separate issue (to be fixed) is that the `auth.linkemails` setting created during setup was not enforced.

This ADR covers the intentional behaviour: that when `auth.linkemails` is enabled, matching an external IdP identity to an existing local account by email address (without additional verification) is accepted as sufficiently secure for the Aperture deployment context.

## Decision
Accept email-match account linking as the intended behaviour when `auth.linkemails` is enabled. Aperture is typically deployed for events where:
1. The identity provider (e.g. a SSO system, Borealis) is operated and trusted by the event organisation.
2. The set of users is a known, controlled group (event staff, volunteers).
3. Email addresses in the IdP are managed by the same organisation that operates Aperture.

In this context, if the IdP asserts an email address matching a local Aperture account, it is reasonable to treat that assertion as proof of identity — the IdP is trusted by the deployment operator.

Operators who do not trust their IdP to correctly assert email addresses should leave `auth.linkemails` disabled (the default), in which case matching falls back to `external_id` only and no email-based linking occurs.

The `auth.linkemails` setting enforcement bug is a separate fix and is not covered by this ADR.

## Consequences
- When `auth.linkemails` is enabled, a compromised or malicious identity provider can link to any Aperture account, including admin accounts, by asserting a matching email.
- This risk is accepted because operators enabling `auth.linkemails` are explicitly choosing to trust their IdP for this purpose.
- Operators should only enable `auth.linkemails` when they control and trust the identity provider.
- The fix to enforce the `auth.linkemails` setting (currently ignored) is tracked separately and must be implemented.

## Supersedes
N/A
