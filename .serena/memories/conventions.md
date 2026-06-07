# Conventions

## Architecture layers (dependency direction)

```
Contracts (interfaces) ← DTO
    ↑
Auth | Http | Cache | Provider   (implement contracts, no cross-deps)
    ↑
Factory/VaultFactory              (wires the graph; magic constants live here only)
    ↑
Bootstrap/VaultBootstrap          (Loader; facade-free)
VaultServiceProvider              (container bindings via Factory)
    ↑
Console commands
Facades/Vault
```

## Naming

- Config typed projection: `VaultConfig` (not "the config array")
- Facade-free boot component: "the Loader" or `VaultBootstrap`
- Bootstrap credentials: "bootstrap-tier keys" (not "secrets")
- `VAULT_SECRET_ID`: "secret-zero"
- Boot with no cache: "cold start" → fail-closed
- Re-fetch with existing cache: "refresh" → grace on failure

## ADR process

10 ADRs exist in `docs/adr/0001–0010.md`. Format: Title / Status / Context / Decision / Consequences.
**Never contradict an ADR without adding a superseding ADR first.** New ADR = next sequence number.
Use `/adr` skill to create new ADRs with correct format.

## Testing

- TDD is mandatory: red → green → refactor
- Min 80% line coverage on `src/`
- Unit tests: `tests/Unit/` (mirrors `src/` structure)
- Feature tests: `tests/Feature/`

## Key constraints

- No `static` properties with mutable state anywhere in `src/`
- No facade usage in `src/Bootstrap/`
- `putenv()` calls restricted to boot context only
- `SecretProvider::fetch()` contract must never be widened to expose Vault internals
