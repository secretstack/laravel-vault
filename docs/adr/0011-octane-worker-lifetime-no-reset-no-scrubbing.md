# Secrets live for the Octane worker's lifetime: no reset listener, no in-memory scrubbing, no live refresh

**Context:** Under Octane the application stays resident and workers are reused across many requests. The loader resolves a service's secrets once at worker boot, injects them into `$_ENV`/`$_SERVER`/`putenv()` ([ADR-0005](./0005-secret-injection-rules.md)) and memoizes them in `SecretStore` (write-once via `??=`). A 2026-06-06 audit asked whether the package should register Octane lifecycle listeners (`RequestReceived`/`RequestTerminated`) to reset, live-refresh, or scrub secrets between requests — and reviewed the residual surfaces of holding plaintext for the worker's life.

**Decision:**
1. **No Octane state-reset or live-refresh listener.** Secrets are write-once per worker, consistent with rotation-via-rolling-restart ([ADR-0004](./0004-stale-while-revalidate-grace-on-refresh.md)). A per-request reset would force a Vault round-trip on every request — re-introducing exactly the runtime Vault traffic the boot-once model exists to eliminate ([ADR-0007](./0007-operational-hardening-resilience-observability-cache.md)). `vault:refresh` remains a separate-process dev/cache-warm tool; it cannot and must not flush a live worker.
2. **No in-memory secret scrubbing.** Decrypted values intentionally persist for the worker's lifetime in `SecretStore` and in `$_ENV`/`$_SERVER`/`putenv()` so existing `env()`/`config()` consumers work unchanged. This footprint is **constant, not growing** — not a leak. Scrubbing would break the transparency contract, and the values would simply be re-read on next access anyway.

**Principle:** State that must survive for the worker's life is set once at boot and never mutated mid-request; rotation is a deployment action (rolling restart), never a runtime one. No mutable `static` state, so nothing leaks or bleeds across workers.

**Accepted limitations (audit 2026-06-06), each minor and knowingly retained:**
- **`vault:install` host edit** patches `bootstrap/app.php` via `str_replace('return $app;', …)` with no backup and no syntax validation; it is idempotent and refuses the Laravel 11 slim skeleton ([ADR-0002](./0002-hybrid-bootstrap-no-zero-touch.md)). Accepted: one-time, operator-run, idempotent — re-running is safe.
- **Unconditional override** — a Vault key overwrites a same-named host `.env` value (Vault is the source of truth, [ADR-0005](./0005-secret-injection-rules.md)). Accepted by design; the deny-list still protects `APP_KEY`/`APP_ENV`/`VAULT_*`.
- **No `connect_timeout`** on the Guzzle client — only the total `timeout` is set, but the bounded-retry total deadline ([ADR-0007](./0007-operational-hardening-resilience-observability-cache.md)) still caps worst-case wall-clock. Accepted.

**Consequences:** The audit found no mutable `static` state, no unbounded growth, and no resource leaks; the package is safe under both PHP-FPM and Octane. The cost is that a rotated secret never reaches a running worker — a rolling restart is mandatory after rotation. None of the three accepted limitations changes the frozen consumer surface, so no SemVer-major bump or fleet-wide `vault:install` is triggered.
