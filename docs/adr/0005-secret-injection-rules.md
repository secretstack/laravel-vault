# Secret injection rules: Vault-wins precedence, protected-key deny-list, config:cache at startup

**Context:** During migration a key often lives in both `.env` and Vault, and the Vault payload can accidentally contain bootstrap-tier keys (the stockv2 path currently contains `APP_KEY`). With `config:cache`, frozen config bypasses transparent env injection entirely. Each of these can silently break a production pod.

**Decisions:**
1. **Vault wins.** When a key exists in both `.env` and Vault, the Vault value is injected over the `.env` one. Vault is the unambiguous source of truth; a forgotten stale `.env` value must never shadow it.
2. **Hard deny-list.** The loader **never** injects `APP_KEY`, `APP_ENV`, or any `VAULT_*` key from the Vault payload, even if present — it logs a warning instead. These are bootstrap-tier: `APP_KEY` decrypts the cache and the `VAULT_*` keys establish the Vault connection, so letting Vault override them creates a chicken-and-egg boot loop (cache encrypted with old `APP_KEY`, decrypt fails, cold path, repeat).
3. **`config:cache` runs at container startup, *after* injection — never at image-build time.** The package ships this mandatory startup sequence (`optimize:clear → config:cache → serve`). A hand-maintained `key_map` is demoted to an optional defensive backstop, not a per-service requirement, because maintaining it across 30 services is unsustainable toil and a silent-failure source.

**Action item:** Remove `APP_KEY` from the `ibid/data/ims/dev/stockv2` Vault path — it should never have been stored there.
