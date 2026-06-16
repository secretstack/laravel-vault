# Local override allow-list: env-wins for named keys, but only when APP_ENV=local

**Context:** Developers working on a feature that spans two services need to repoint one
service's "other-service URL" (e.g. `STOCKV2_SERVICE_URL`) at their own `localhost` while every
other secret still comes from Vault. Today they cannot:

- Vault access is **read-only**, and the dev/staging values are **shared** — editing them in
  Vault would break every other developer, so that is not an option (nor desired).
- [ADR-0005](0005-secret-injection-rules.md) makes precedence **Vault-wins**: `EnvInjector`
  overwrites `$_ENV` unconditionally, so a value placed in the local `.env` is clobbered by the
  Vault value at boot. The override is impossible by design.
- `VAULT_ENABLED=false` is all-or-nothing — it turns the package into a complete no-op, losing
  *every* other secret. Not a viable middle ground.

The Vault-wins rule exists for a specific reason: a forgotten **stale `.env` value baked into a
production image** must never shadow the real secret. That rationale **does not hold on a
developer's laptop** — there is no baked image, and the developer is the legitimate source of
truth for the override. So the invariant should be inverted *there*, and **only** there.

**Decision:** Amends [ADR-0005](0005-secret-injection-rules.md). Vault-wins remains the rule
everywhere except an explicit, gated, observable local exception.

1. **Allow-list, not blanket.** A new bootstrap-tier key `VAULT_LOCAL_OVERRIDES` holds a
   comma-separated list of keys whose **local `.env` value wins over Vault**. Only listed keys
   are affected; every other key still comes from Vault. Explicit and auditable — no accidental
   shadowing of a secret a developer didn't mean to override.
2. **Gated to `APP_ENV === 'local'`.** The inversion is active **iff** `APP_ENV` is `local`.
   On dev/staging/prod pods (`APP_ENV` is `dev`/`staging`/`production`) it is inert — Vault-wins
   holds exactly as ADR-0005 specifies. `APP_ENV` defaults to `production` when unset, so the
   safe posture is the default. **Production is untouched.**
3. **Nothing is exported.** The mechanism only *declines to overwrite* — no secret is ever
   written to a new `.env`, dumped, or copied to disk. There is literally nothing to "pull" for
   any environment; the production secret graph is never materialized outside the process.
4. **One policy, both boot phases.** The decision lives in a single `OverridePolicy`
   (`src/Secrets/OverridePolicy.php`) consulted by **both** the Loader's `EnvInjector` *and* the
   ServiceProvider's `applyKeyMapOverrides` (the `key_map` backstop). Without this, an override
   would work everywhere *except* for keys that happen to be in `key_map` — the backstop would
   re-push the Vault value into `config()` at runtime and silently defeat it.
5. **Deny-list still wins.** `APP_KEY`/`APP_ENV`/`VAULT_*` are checked before the override, so an
   override can never resurrect a bootstrap-tier key (ADR-0005 deny-list intact).
6. **Leftover is surfaced, never obeyed.** A non-empty `VAULT_LOCAL_OVERRIDES` in a non-local
   environment is ignored *and* logged (`vault.override.ignored_nonlocal`, key names only per
   [ADR-0007](0007-operational-hardening-resilience-observability-cache.md)). We do **not** throw
   — killing a production pod over a stray env var is worse than ignoring it — but it is visible
   in logs/SIEM. An applied override logs `vault.override.hit` (key name only).

**Consumer-surface & SemVer:** adds `VAULT_LOCAL_OVERRIDES` and the `config/vault.php`
`local_overrides` key to the frozen consumer surface (DESIGN §13). Both are **additive and
backward-compatible** — absent config means no overrides, identical to prior behavior — so this
ships as a **minor** `v1.x` release, not a major.

**Precondition (why no export command):** developer laptops can reach the Vault API directly
(read-only, dev/staging paths) and hold a per-environment `SECRET_ID` ([ADR-0009](0009-secret-id-per-environment-not-per-service.md)).
Secrets therefore flow live into the local process exactly as on a pod, so the old "copy JSON
from the Vault UI → hand-convert to `.env`" toil disappears with no new command. If laptops ever
lose direct Vault API access, a `vault:pull` export would need its own ADR — with an explicit
production hard-stop, since *that* path would materialize secrets to disk.

**Usage:**

```dotenv
# local .env
APP_ENV=local
VAULT_ENABLED=true                                  # all other secrets still come from Vault
VAULT_LOCAL_OVERRIDES="STOCKV2_SERVICE_URL,PAYMENT_URL"
STOCKV2_SERVICE_URL=http://localhost:8002           # this value survives; Vault does not win
```
