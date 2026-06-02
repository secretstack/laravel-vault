# Fail-closed by default in production; cold start is coupled to Vault liveness

**Context:** The package strips secrets out of `.env`. This breaks the monolith's `fail_open` semantics: fail-open used to mean "fall back to the plaintext value still in `.env`", but once `.env` is stripped there is nothing to fall back to. A cold pod (ephemeral K8s filesystem = no cache yet) that can't reach Vault under `fail_open=true` would boot "successfully" with null secrets and serve revenue traffic against empty/default credentials.

**Decision:** Default to **fail-closed in production**. A pod that cannot obtain its secrets at boot throws and exits non-zero, so K8s keeps the old healthy pods serving and the rollout halts. `fail_open=true` is reserved for local/dev and, when set, falls back only to a last-known-good cache — never to a phantom `.env`. Cold-start resilience relies on retries + jitter + a circuit breaker plus Kubernetes readiness-probe gating (option **a**). This makes "Vault must be reachable during a rollout" an accepted operational constraint.

**Deferred:** A Vault Agent sidecar (option c) that decouples cold-start from Vault liveness is the eventual target architecture but is out of scope for v1.
