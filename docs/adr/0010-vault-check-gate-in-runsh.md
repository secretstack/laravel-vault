# vault:check doubles as the deploy-time gate via run.sh, with boot-equivalent semantics

**Context:** CI/Jenkins cannot host a pre-deploy Vault gate. The container startup script (`run.sh`, which already has `set -e`) is the only place under per-service control where a gate can run. The goal: a failed Vault check should abort container startup so the rollout halts and old pods keep serving (fail-closed, [ADR-0003](./0003-fail-closed-by-default-in-production.md)).

**Problem:** The current `vault:check` performs a fresh `login()` + `readKvV2()` on every run, so its exit code means "is Vault reachable right now" — not "can this pod boot." Those diverge exactly when the stale-while-revalidate grace ([ADR-0004](./0004-stale-while-revalidate-grace-on-refresh.md)) applies: a warm pod with a valid/stale cache can boot through a transient Vault blip, but the as-written check would fail and `set -e` would kill a recoverable pod. The gate would be *stricter than runtime*.

**Decision:** Add a `--gate` mode whose exit code mirrors the **bootstrap loader's** success condition, not raw Vault reachability.
- `vault:check` (plain): human diagnostic, always exits 0, prints every step.
- `vault:check --gate`: exits non-zero **iff** the loader would fail — no secrets obtainable by any path (fresh ∨ valid cache ∨ stale grace). `run.sh` runs this first, before the `config:cache` block, so a true cold-failure aborts early with a clean message.

**Principle:** A startup gate's pass/fail must track the loader's configured fail-mode exactly, or it produces false kills (stricter than runtime) or false passes (more lenient than runtime).

**Accepted limitation:** A `run.sh` gate runs after build+push, so it cannot catch a bad *package version* before the image exists (a CI gate could). It halts the deploy safely but after paying for the build. Version-safety therefore still relies on `^1.0` pinning + canary-service-first rollout; the `run.sh` gate covers connectivity/config/secret failures at deploy time.
