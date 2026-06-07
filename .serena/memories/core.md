# laravel-vault — Core

Private Laravel package: HashiCorp Vault KV-v2 secret injection for a fleet of 30+ services.
PSR-4 `Vaultenv\Vault\` → `src/`. Facade alias `Vault`. Config key `vault`.

## Source map

```
src/
  Bootstrap/VaultBootstrap.php      # facade-free Loader; called from bootstrap/app.php
  Config/VaultConfig.php             # typed projection of config/vault.php (fromEnv + fromArray)
  Factory/VaultFactory.php           # assembles client→auth→cache→provider→store graph
  Auth/AppRoleAuth.php               # AppRole token exchange
  Http/GuzzleVaultClient.php         # raw Guzzle; retry/backoff/jitter live here
  Cache/EncryptedFileCache.php       # APP_KEY-encrypted per-pod cache
  Provider/VaultSecretProvider.php   # implements SecretProvider; KV-v2 details here only
  Secrets/EnvInjector.php            # writes to $_ENV/$_SERVER/putenv at boot
  Secrets/SecretStore.php            # in-memory store; Vault::get() accessor
  Contracts/                         # SecretProvider, SecretCache, VaultClient, AuthMethod
  DTO/VaultToken.php, VaultSecret.php
  Facades/Vault.php
  Console/CheckCommand.php, RefreshCommand.php, InstallCommand.php
  Exceptions/VaultException.php, SecretProviderException.php
  Support/FileLogger.php
  VaultServiceProvider.php
docs/adr/0001–0010.md               # decisions; do not contradict without adding superseding ADR
```

## Boot phases (critical)

Two distinct phases share `VaultConfig` + `VaultFactory`:
1. **Loader** (`VaultBootstrap::inject`) — `afterBootstrapping(LoadEnvironmentVariables)`, facade-free, reads raw `$_ENV`
2. **ServiceProvider** — standard Laravel boot, reads `config('vault')`, binds container singletons

## Non-negotiable invariants (breaking = production incident)

1. No facade on Loader path — facades don't exist yet at that hook
2. No `static` mutable state — leaks across Octane workers
3. Immutable-per-worker — `putenv()` at boot only, never in a coroutine
4. Deny-list: never inject `APP_KEY`, `APP_ENV`, or any `VAULT_*` key from Vault
5. Never log secret values — key names / counts / durations only
6. Fail-closed in prod; grace on refresh (cold cache = throw; stale cache + failed refresh = serve stale)
7. Frozen consumer surface — changes to bootstrap line, VAULT_* keys, `Vault::get()`, `SecretProvider`, `config/vault.php` keys, or artisan commands require major version bump + fleet `vault:install`
8. `SecretProvider::fetch()` returns `array<string,string>` — no Vault internals (lease, KV-v2 version, AppRole) leak through the contract

## Related memories

- Runtime commands and container invocation: `mem:suggested_commands`
- Versions, deps, test tooling: `mem:tech_stack`
- Naming, patterns, ADR process: `mem:conventions`
- Definition-of-done checklist: `mem:task_completion`
