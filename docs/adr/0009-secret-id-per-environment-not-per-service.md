# SECRET_ID is provisioned per-environment, not per-service

**Context:** Supersedes control #2 of [ADR-0008](./0008-accepted-risk-secret-zero-baked-into-image.md), which recommended a distinct `SECRET_ID` per service *and* per environment. DevOps has decided `SECRET_ID` differs **per environment only** — all services within an environment share one AppRole credential.

**Implication:** A single AppRole shared across all services in an environment must have a Vault policy that can read every service's path in that environment (a shared role cannot serve each service its own path otherwise). Therefore:
- **Environment isolation holds:** prod's `SECRET_ID` is distinct from dev/staging, so a leaked dev image cannot read prod secrets. This is the primary boundary and it is preserved.
- **Intra-environment blast radius = the entire environment:** one leaked prod image → the shared prod `SECRET_ID` → a token that reads *all* prod services' secrets. Per-service policy scoping can no longer contain an intra-env leak.

**Decision:** Accept the per-environment model (DevOps's call), and shift the security weight from preventive scoping onto detective and perimeter controls, which now carry the load:
1. **Vault audit device + anomaly alerting is now mandatory, not optional** — it is the primary control against an intra-env leak (alert on unexpected source IPs, read-volume spikes, reads of paths a caller doesn't normally touch).
2. **The shared per-env policy must still be scoped to IBID paths only** (e.g. `ibid/data/+/<env>/*`), never cross-team/global. This caps the leak at "all IBID secrets in one env" rather than "the entire Vault."
3. **Prod image registry pull-RBAC is tightened to the minimum** — with intra-env scoping gone, registry access control is a primary perimeter control.
4. Rotation cadence and "rotate the chat-exposed dev `SECRET_ID`" from ADR-0008 still stand.

**Escalation trigger:** If the per-env policy is *not* scoped to IBID paths only, or Vault audit logging/alerting is not enabled, this moves from accepted-risk to blocker — those two controls are what make the per-env model survivable.
