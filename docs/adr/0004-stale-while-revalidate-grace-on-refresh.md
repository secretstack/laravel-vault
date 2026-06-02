# Stale-while-revalidate grace on refresh; fail-closed only on truly cold start

**Context:** Refines [ADR-0003](./0003-fail-closed-by-default-in-production.md). Secrets are immutable for an Octane worker's lifetime; rotation is via rolling restart. But Octane recycles workers after `--max-requests`, and a fresh worker re-runs the bootstrap loader. If the per-pod cache has expired (past TTL) at the moment a worker recycles *and* Vault has a transient blip, a strict fail-closed policy would kill an otherwise-healthy pod that had been serving correctly for weeks.

**Decision:** Distinguish two failure situations instead of treating them identically:
- **Cold start — no secrets available at all** → **fail-closed** (throw, exit non-zero; do not serve traffic without credentials).
- **Refresh failure — a usable (even expired) cache exists** → **serve last-known-good**, log loudly, emit a metric / Sentry warning, and keep serving.

**Why:** A transient Vault outage during a routine worker recycle should not take down a healthy revenue-serving pod. Serving slightly-stale-but-known-good secrets is safer than crashing. Hard fail-closed is reserved for the genuinely unrecoverable case where there is nothing to fall back to. This is strictly more robust than the monolith's all-or-nothing fail-open.
