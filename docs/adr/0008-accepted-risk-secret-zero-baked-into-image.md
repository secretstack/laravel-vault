---
status: accepted (risk accepted) — review by 2026-12-01
---

# ACCEPTED RISK: secret-zero (VAULT_SECRET_ID) and APP_KEY are baked into the image

**Context:** The pipeline fetches `.env` from a separate config repo ("Get Latest .env") and `COPY`s it into the image (`COPY .env /app/.env`). Not all production targets have ArgoCD / runtime K8s secret injection, so secret-zero cannot universally be delivered as a runtime env var in v1.

**Decision:** Accept, for v1, that `VAULT_SECRET_ID` (AppRole password — the master key to the service's entire Vault path) and `APP_KEY` ship **inside an image layer**. This is a deliberate, time-boxed risk acceptance, not an oversight.

**Risk:** Anyone who can pull the image can extract `VAULT_SECRET_ID`, mint a token, and read every secret in that service's Vault path. The Vault migration therefore reduces, but does not eliminate, image-layer secret exposure — it converts "secrets in the image" into "the key to the secrets in the image."

**Mandatory compensating controls (shrink the blast radius):**
1. **Least-privilege AppRole policy** — the role's Vault policy must grant read on *only* that service's path (e.g. `ibid/data/ims/dev/stockv2`) and nothing else. Verify per service.
2. ~~**Distinct `SECRET_ID` per service AND per environment** — never shared. Blast radius = one service, one environment.~~ **Superseded by [ADR-0009](./0009-secret-id-per-environment-not-per-service.md):** DevOps provisions `SECRET_ID` per-environment only. Intra-env blast radius is the whole environment; security weight shifts to detective controls (Vault audit) and perimeter controls (registry RBAC).
3. **Vault audit device + alerting** on anomalous reads (unexpected source IP, volume spikes) — the key detective control.
4. **Tight registry pull RBAC** — minimize which identities can pull images.
5. **Rotation cadence** — rotate `SECRET_ID` via the config repo + rebuild on a fixed schedule (≥ quarterly) and immediately on suspected compromise.
6. **Rotate the dev `SECRET_ID` now** — it was shared in chat during setup and is therefore already considered exposed.

**Exit path:** When ArgoCD / runtime secret injection (or AKS Workload Identity + Vault Azure auth, which eliminates secret-zero entirely) is available on a target, move `VAULT_SECRET_ID` and `APP_KEY` to runtime K8s env vars and stop baking them. The package needs no code change for this — `Env::get()` already reads runtime env vars transparently.
