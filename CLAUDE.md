# vaultenv/laravel-vault

Private Laravel package: centralized HashiCorp Vault secret management for the fleet
(30+ services). Fetches a service's secrets from Vault at boot and injects them so existing
`env()`/`config()` consumers work **unchanged**.

**Read before changing anything:** `DESIGN.md` (build blueprint), `docs/adr/` (decisions
0001–0013), `CONTEXT.md` (glossary). Do not contradict an ADR without recording a
superseding one.

## Stack & compatibility
- PHP `^8.2`; Laravel `10 | 11 | 12 | 13`. **No Lumen** (ADR-0001).
- Depend on `illuminate/*` components, never `laravel/framework`.
- Tests: Orchestra Testbench + PHPUnit.

## Commands (run inside the `php8.3` container)
Host `~/podman-volumes/www/ibid/laravel-vault` ↔ container `/var/www/html/ibid/laravel-vault`.
```bash
# Install deps
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 composer install
# Run tests
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 vendor/bin/phpunit --testsuite=Unit
# Syntax check a file
docker exec -i -w /var/www/html/ibid/laravel-vault php8.3 php -l <file>
```

## Workflow
- **TDD is mandatory**: red → green → refactor. Write the failing test first, implement to
  pass, then refactor. Target ≥ 80% coverage on `src/`.
- Commits: conventional format, **no AI attribution** (org setting).

## Non-negotiable invariants (breaking one is a production incident)
1. **Boot-order:** the Loader runs facade-free at `afterBootstrapping(LoadEnvironmentVariables)`,
   before `config()`/facades exist. Never use a facade on the Loader path (ADR-0002).
2. **No `static` mutable state** anywhere — it leaks/shares across Octane workers (DESIGN §9).
3. **Immutable-per-worker:** resolve secrets once at boot, inject, freeze. Never mutate
   `$_ENV`/`config()`/already-built singletons mid-request. `putenv()` only at boot, never in
   a coroutine (DESIGN §9, Q5).
4. **Deny-list:** never inject `APP_KEY`, `APP_ENV`, or any `VAULT_*` key from Vault (ADR-0005).
5. **Never log secret values** — event/key names, counts, durations only (ADR-0007).
6. **Fail-closed in prod; stale-grace on refresh** (ADR-0003/0004). Cold (nothing cached) =
   throw; refresh failure with a usable cache = serve stale.
7. **Frozen consumer surface** = the only public API (change = major + fleet-wide
   `vault:install`): the `bootstrap/app.php` line, the `VAULT_*`/`APP_KEY` keys, `Vault::get()`
   + `SecretProvider`, `config/vault.php` keys, the artisan commands. SemVer against these only
   (DESIGN §13).
8. **`SecretProvider::fetch()` returns `array<string,string>`** — no Vault-isms (lease, KV-v2
   version, AppRole) leak through the contract (ADR-0006).

## Namespace
PSR-4 `Vaultenv\Vault\` → `src/`. Facade alias `Vault`. Config key `vault`.
