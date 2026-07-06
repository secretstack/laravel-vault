# Environment defaults path: per-env second path, atomic client-side merge, service wins

## Status
Accepted (2026-07-06)

## Context
Fleet-shared values (e.g. `APP_TIMEZONE`) are duplicated into every service's Vault path.
We want one place to set them, with each service able to shadow a key in its own path.
Vault's KV engine has no native inheritance between paths — layering is always client-side
(same pattern as Spring Cloud Vault / consul-template), so this is package behavior.

## Decision
1. **Per-environment, never fleet-wide.** One defaults path per environment
   (e.g. `ims/dev/_defaults`, `ims/prod/_defaults`). Secret-zero and Vault policy are
   per-environment (ADR-0009); each env's policy adds read on its own defaults path only.
   Blast radius of a bad defaults write is one environment.
2. **Explicit, optional config.** New `VAULT_DEFAULTS_PATH` env key /
   `config('vault.defaults_path')`. Empty or absent = no defaults fetch = prior behavior
   exactly. Additive to the frozen consumer surface (DESIGN §13) → **minor** version, no
   forced fleet-wide `vault:install`. No path derivation by convention — what is fetched
   is what is written in the pod spec.
3. **Atomic merge inside the provider.** `VaultSecretProvider` fetches both paths and
   merges (service key shadows defaults key) before returning. One payload, one cache file
   of the *merged* result. `SecretProvider::fetch(): array<string,string>` is untouched
   (ADR-0006); cold/grace semantics are untouched (ADR-0003/0004): either fetch failing =
   the fetch failed — cold start throws, refresh serves merged last-known-good. A pod never
   boots with a partial (defaults-missing) secret set.
4. **Configured-but-missing path (404) is a hard error**, in every environment. Treating it
   as empty lets a typo silently disable the whole defaults layer while the pod boots green
   and the Gate passes. Existing-but-empty is fine. Ops consequence: create the env's
   defaults path in Vault *before* any service in that env sets `VAULT_DEFAULTS_PATH`.
5. **Precedence, lowest to highest:** environment defaults → service path → local override
   (ADR-0014, `APP_ENV=local` + allow-listed only). The deny-list (ADR-0005) applies to the
   merged payload, so bootstrap-tier keys are never injected from either path. The `key_map`
   backstop and the Gate see the merged payload for free.
6. **Observability per ADR-0007:** log fetch/merge as key names and counts only — including
   which default keys were shadowed by the service path — never values.

## Alternatives rejected
- **Fleet-wide single defaults path** — requires every env's policy to reach outside its
  namespace, and one bad write hits dev and prod simultaneously.
- **Three-level merge (fleet → env → service)** — no use case for the fleet layer yet.
- **Path derived by convention from `VAULT_SECRET_PATH`** — bakes a path-shape assumption
  into code and fails silently for non-conforming paths.
- **Independently cached layers** — mixed-vintage config (fresh service + stale defaults)
  nobody can reason about mid-incident, plus a second cache/TTL/failure matrix.
- **Best-effort defaults fetch** — the layer would silently stop applying exactly when
  Vault is flaky.

## Consequences
- One extra Vault read per boot/refresh per service that opts in.
- `config/vault.php` gains `defaults_path`; `vault:check` should display it.
- Naming: canonical term is **environment defaults** (see CONTEXT.md) — not "global",
  which wrongly implies fleet-wide and authoritative.
