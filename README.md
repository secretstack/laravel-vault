# ibid/laravel-vault

Centralized HashiCorp Vault secret management for IBID Laravel services. Fetches a
service's secrets from Vault at boot and feeds them to existing `env()`/`config()`
consumers **transparently** — adopt it with one line of code.

- **Design blueprint:** [DESIGN.md](./DESIGN.md)
- **Decisions:** [docs/adr/](./docs/adr/) (ADR-0001…0010)
- **Glossary:** [CONTEXT.md](./CONTEXT.md)

> Status: **v1 implemented (TDD).** 50 tests green, ~87% line coverage on `src/`
> (PHP 8.2 / Laravel 11 / PHPUnit 11). See [DESIGN.md](./DESIGN.md) for the
> architecture and [docs/adr/](./docs/adr/) for the decisions.

## Requirements

- PHP `^8.2`
- Laravel `9 | 10 | 11` (no Lumen — ADR-0001)
- HashiCorp Vault with a KV-v2 mount and an AppRole

## Install (target onboarding flow)

```bash
composer require ibid/laravel-vault:^1.0
php artisan vault:install      # patches bootstrap/app.php (prints a diff)
```

Then add the startup gate to your container entrypoint (`run.sh`), **before** caching config:

```bash
set -e
php artisan vault:check --gate     # halts a bad rollout; old pods keep serving
php artisan optimize:clear
php artisan config:cache           # freezes config WITH Vault values (ADR-0005)
php artisan route:cache && php artisan view:cache
```

## Configure (`.env` — bootstrap-tier only)

```dotenv
VAULT_ENABLED=true
VAULT_ADDR=https://vault.example
VAULT_AUTH_MOUNT=approle
VAULT_ROLE_ID=
VAULT_SECRET_ID=
VAULT_SECRET_PATH=ibid/data/<service>/<env>
VAULT_NAMESPACE=
VAULT_FAIL_OPEN=false        # true only in local/dev (ADR-0003)
VAULT_CACHE_ENABLED=true
VAULT_CACHE_TTL=2764800
VAULT_HTTP_TIMEOUT=5
VAULT_HTTP_RETRIES=3
VAULT_TLS_VERIFY=true

APP_KEY=base64:...           # encrypts the local secret cache; never sourced from Vault
```

All other secrets live in Vault and resolve through `config()`/`env()` unchanged.
On-demand access: `Vault::get('SOME_KEY')`.

## Behavior in one line

Secrets are resolved **once per worker at boot**, injected, and frozen. A cold pod that
can't reach Vault **fails closed** (rollout halts); a warm pod through a transient blip
serves **last-known-good** cache. Rotation = update Vault + rolling restart.

## Commands

| Command | Purpose |
|---|---|
| `vault:install` | Patch `bootstrap/app.php` with the boot hook |
| `vault:check [--gate]` | Diagnose connectivity / act as the startup gate |
| `vault:refresh` | Bust the cache and re-fetch (dev / cache-warm only) |

## License

Proprietary — internal IBID use only.
