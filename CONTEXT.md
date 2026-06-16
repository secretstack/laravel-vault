# vaultenv/laravel-vault

A private Laravel package that fetches a service's secrets from HashiCorp Vault at boot and makes them available to existing `env()`/`config()` consumers transparently, across a fleet of 30+ services.

## Language

**Secret-zero**:
The bootstrap credential (`VAULT_SECRET_ID`) that, with the role id, mints a Vault token granting read access to a service's secrets. The master key — if it leaks, everything it can reach leaks.
_Avoid_: "the secret id" (ambiguous with the secrets it unlocks).

**Bootstrap-tier keys**:
The keys that must exist *before* Vault can be contacted and are therefore **never** sourced from Vault: all `VAULT_*` keys and `APP_KEY`. The injection deny-list enforces this.
_Avoid_: calling these "secrets" interchangeably with Vault-managed secrets — they are a distinct tier.

**Transparent injection**:
Writing fetched secrets into `$_ENV`/`$_SERVER`/`putenv()` at boot, before `LoadConfiguration`, so existing `env()`/`config()` calls resolve Vault values with no code change.
_Avoid_: "config override" (that's the narrower `key_map` backstop, not the primary mechanism).

**Local override** (`OverridePolicy`):
A gated, explicit exception to **Vault-wins** precedence: for keys named in `VAULT_LOCAL_OVERRIDES`, the local `.env` value wins over Vault — but **only** when `APP_ENV=local` (ADR-0014). For repointing a service URL at `localhost` during cross-service dev without touching shared Vault values. The decision lives in one `OverridePolicy` consulted by both the **Loader** (`EnvInjector`) and the `key_map` backstop, so precedence cannot drift between boot phases. Nothing is exported to disk; the mechanism only *declines to overwrite*.
_Avoid_: conflating with **transparent injection** (the default mechanism) or "config override" (the `key_map` backstop) — local override is the inversion of precedence, not the injection itself.

**The Loader**:
The facade-free bootstrap component (`VaultBootstrap::inject($app)`) invoked from a single `afterBootstrapping(LoadEnvironmentVariables)` line in a consumer's `bootstrap/app.php`. Runs before facades exist.

**Resolved config** (`VaultConfig`):
The typed, validated projection of `config/vault.php` that both the **Loader** (`fromEnv()`, reading raw env at boot) and the `VaultServiceProvider` (`fromArray()`, reading `config('vault')` at runtime) build. Casts, defaults, and the completeness check (`assertUsable()`) live here, not smeared across the two boot phases.
_Avoid_: conflating with `config/vault.php` itself — that is the raw Laravel config array; `VaultConfig` is its typed, validated projection.

**The Factory** (`VaultFactory`):
The in-process assembler that wires the secret-fetching graph (client → auth → cache → provider → store) from a **Resolved config**. The magic constants — max backoff, AES cipher, the retry deadline formula, the `base64:`-strip and empty-`APP_KEY` guard — live here and nowhere else. Both the **Loader** and the `VaultServiceProvider` build through it; they differ only in config source and logger.
_Avoid_: "the builder" / "the bindings" — the ServiceProvider's container bindings delegate to **The Factory**; they are not themselves the assembler.

**Provider** (`SecretProvider`):
The single-method contract (`fetch(): array<string,string>`) abstracting the secret source. One implementation exists: `VaultSecretProvider`. Vault-isms (lease, KV-v2 version, AppRole) live inside it, never in the contract.

**Cold start**:
A boot where no secrets are obtainable from memory *or* cache — the pod has nothing to fall back to. Triggers **fail-closed**.
_Avoid_: using "cold start" loosely for any pod boot; a pod with a valid cache file is not cold in this sense.

**Refresh**:
A re-fetch attempted when a usable (possibly expired) cache already exists. A failed refresh triggers **grace**, not failure.

**Grace** (stale-while-revalidate):
Serving the last-known-good cache — even past TTL — when a *refresh* cannot reach Vault, rather than crashing a pod that was serving fine.

**Fail-closed** / **Fail-open**:
Boot behavior when secrets cannot be obtained. **Fail-closed** (production default): throw, exit non-zero, let K8s keep old pods serving. **Fail-open** (dev only): fall back to last-known-good cache. Note: fail-open no longer means "fall back to `.env`" — that meaning died when secrets were stripped from `.env`.

**The Gate**:
`vault:check --gate` run in `run.sh` at container startup. Its exit code mirrors the Loader's success condition (boot-equivalent), so it halts a rollout on a true cold-failure but does not kill pods that grace would save.

## Relationships

- A **Service** has one Vault **path** per **environment**; one **secret-zero** is shared by all services *within* an environment (not per-service).
- The **Loader** and the `VaultServiceProvider` each build a **Resolved config** and pass it to **The Factory**, which assembles the graph the **Provider** drives — so wiring knowledge lives in one place across both boot phases.
- The **Loader** calls the **Provider**, which returns secrets that are then **transparently injected**.
- **Cold start** → fail-closed; **Refresh** failure → **grace**. The **Gate** enforces the same distinction at startup.
- **Bootstrap-tier keys** are inputs to the Loader and are never outputs of the **Provider**.

## Example dialogue

> **Dev:** "If Vault is down when a pod starts, does the pod come up?"
> **Architect:** "Depends whether it's a **cold start** or a **refresh**. Cold — nothing cached — it **fail-closes** and the rollout halts. If there's a usable cache, **grace** serves last-known-good and it boots. The **Gate** in `run.sh` makes the same call, so it won't kill a pod that grace would save."

## Flagged ambiguities

- "fail-open" was carried over from the monolith meaning "fall back to `.env`". Resolved: in the package, `.env` holds no secrets, so fail-open now means "fall back to last-known-good cache," and is dev-only.
- "secret id" was used for both **secret-zero** and the Vault-managed secrets. Resolved: **secret-zero** is the bootstrap credential only.
