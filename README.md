# Laravel Vault

> Centralized [HashiCorp Vault](https://www.vaultproject.io/) secret management for Laravel.
> Fetches a service's secrets from Vault **once at boot** and injects them into the environment
> so your existing `env()` / `config()` calls keep working **unchanged** — zero per-request cost,
> Octane-safe, fail-closed in production.

<p>
  <a href="https://packagist.org/packages/secretstack/laravel-vault"><img src="https://img.shields.io/packagist/v/secretstack/laravel-vault.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <img src="https://img.shields.io/badge/PHP-%5E8.3-777BB4.svg?style=flat-square" alt="PHP ^8.3">
  <img src="https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012%20%7C%2013-FF2D20.svg?style=flat-square" alt="Laravel 9 | 10 | 11 | 12 | 13">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License MIT"></a>
</p>

---

## Table of contents

- [Why this exists](#why-this-exists)
- [Features](#features)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Artisan commands](#artisan-commands)
- [Production deployment](#production-deployment)
- [Laravel Octane & long-running workers](#laravel-octane--long-running-workers)
- [Caching](#caching)
- [Security model](#security-model)
- [Extending: custom secret providers](#extending-custom-secret-providers)
- [Stability & SemVer — the frozen consumer surface](#stability--semver--the-frozen-consumer-surface)
- [Architecture overview](#architecture-overview)
- [Development & testing](#development--testing)
- [Documentation & ADRs](#documentation--adrs)
- [Contributing](#contributing)
- [License](#license)

---

## Why this exists

Most Laravel deployments ship secrets as a plaintext `.env` baked into the Docker image. That
means **no rotation without a rebuild, no central audit, and a blast radius of "everything"** the
moment an image leaks.

`laravel-vault` moves those secrets into HashiCorp Vault and fetches them at boot. Because it
injects the values into the environment **before Laravel loads its configuration**, every existing
`env('DB_PASSWORD')` and `config('database.connections.mysql.password')` call resolves the Vault
value with **no application code change**. Adopting the package is a one-line edit to
`bootstrap/app.php` — and even that is automated by an artisan command.

It was built to manage secrets uniformly across a large fleet of services, so the production
posture is deliberately conservative: **fail-closed by default**, **stale-while-revalidate grace**
on transient outages, and **zero secret traffic on the request hot path**.

## Features

- **Transparent injection** — secrets land in `$_ENV` / `$_SERVER` / `putenv()` at boot, before
  `config()` is built. Your code doesn't change.
- **Runtime accessor** — `Vault::get('KEY')` facade (and an injectable `SecretStore`) for explicit lookups.
- **AppRole auth + KV-v2** — the v1 Vault driver, with `X-Vault-Namespace` support (Vault Enterprise).
- **Encrypted per-pod cache** — AES-256-CBC via your `APP_KEY`, on a `0600` file, so boots are fast and survive blips.
- **Fail-closed in production** — a pod that can't obtain secrets exits non-zero so your orchestrator keeps the old pods serving.
- **Stale-while-revalidate grace** — a transient Vault blip during a worker recycle serves last-known-good instead of crashing a healthy pod.
- **Octane / Swoole / FrankenPHP / RoadRunner safe** — immutable-per-worker, no mutable static state, no per-request I/O.
- **Resilient HTTP** — bounded retries, exponential backoff with jitter, per-request timeout, and a total deadline.
- **Hard deny-list** — `APP_KEY`, `APP_ENV`, and any `VAULT_*` key are never injected from Vault.
- **Safe observability** — a dedicated `vault` log channel that records event names, key names, and counts — **never secret values**.
- **Pluggable** — a one-method `SecretProvider` contract you can implement for other backends.

## How it works

The single fact that shapes the whole design is **Laravel's boot order**:

```
1. LoadEnvironmentVariables   ← .env parsed into $_ENV
2. LoadConfiguration          ← config/*.php evaluated; env() is frozen INTO config here
3. RegisterProviders          ← normal package auto-discovery happens HERE (too late)
4. BootProviders
```

For injection to be transparent, the secrets must be in the environment **between steps 1 and 2**.
Service-provider auto-discovery runs at step 3 — after config is already frozen. So the package has
**two entry points into one codebase** ([ADR-0002](docs/adr/0002-hybrid-bootstrap-no-zero-touch.md)):

- **The Loader** (`VaultBootstrap::inject()`) — facade-free, hooked on
  `afterBootstrapping(LoadEnvironmentVariables)`. It runs before `config()` and facades exist and
  performs the injection. This is the one line `vault:install` adds to `bootstrap/app.php`.
- **The ServiceProvider** — auto-discovered at step 3. It binds the runtime `Vault::get()`
  accessor, the artisan commands, and the optional `config:cache` backstop.

Both paths share the same client / auth / cache / provider classes — only the wiring differs.

Cold-boot lifecycle (happy path):

```
bootstrap/app.php → afterBootstrapping(LoadEnvironmentVariables)
  └─ VaultBootstrap::inject($app)
       read VAULT_* / APP_KEY (facade-free)
         → cache MISS (fresh pod)
         → SecretProvider::fetch()
             → AppRoleAuth.authenticate()  → VaultToken
             → VaultClient.readKvV2(path)  → VaultSecret
         → cache put (encrypted)
         → EnvInjector::inject()   (deny-list enforced)
  → LoadConfiguration   (config now reads the injected values ✓)
  → RegisterProviders → VaultServiceProvider
```

## Requirements

- **PHP** `^8.3`
- **Laravel** `9`, `10`, `11`, `12`, or `13` — **Lumen is not supported** ([ADR-0001](docs/adr/0001-vault-package-targets-laravel-9-php-82.md))
- A **HashiCorp Vault** server with a **KV-v2** secrets mount and **AppRole** auth enabled
- `ext-json`

The package depends on individual `illuminate/*` components (never `laravel/framework` directly),
`guzzlehttp/guzzle ^7`, and `psr/log ^3`.

## Installation

```bash
composer require secretstack/laravel-vault:^1.0
```

Wire the boot hook into `bootstrap/app.php`:

```bash
php artisan vault:install
```

`vault:install` is **idempotent** and patches the Laravel 9/10 skeleton automatically. For the
**Laravel 11+ slim skeleton** (which has no `return $app;` line), the command cannot safely
auto-edit the file — it prints manual instructions and the exact snippet to paste:

```php
$app->afterBootstrapping(
    \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    fn ($app) => \Vaultenv\Vault\Bootstrap\VaultBootstrap::inject($app)
);
```

Publishing the config file is **optional** (it is auto-merged at runtime), but available:

```bash
php artisan vendor:publish --tag=vault-config
```

> **Local development:** set `VAULT_ENABLED=false` to make the package a complete no-op.

## Configuration

Every option is read from an environment variable with a safe default, so `config:cache` stays
deterministic. The published file is [`config/vault.php`](config/vault.php).

| Env var | Default | Purpose |
|---|---|---|
| `VAULT_ENABLED` | `false` | Master switch. `false` = no-op (no Vault calls). |
| `VAULT_ADDR` | `http://127.0.0.1:8200` | Vault server address. |
| `VAULT_NAMESPACE` | _(empty)_ | Vault Enterprise namespace (sent as `X-Vault-Namespace`). |
| `VAULT_AUTH_MOUNT` | `approle` | AppRole auth mount path. |
| `VAULT_ROLE_ID` | _(empty)_ | AppRole role id. |
| `VAULT_SECRET_ID` | _(empty)_ | AppRole secret id — your **bootstrap credential** (see [Security](#security-model)). |
| `VAULT_SECRET_PATH` | _(empty)_ | KV-v2 path, e.g. `secret/data/my-app/production`. |
| `VAULT_FAIL_OPEN` | `false` | Keep `false` (fail-closed) in production. `true` is **dev-only** and falls back to cache. |
| `VAULT_CACHE_ENABLED` | `true` | Enable the encrypted file cache. |
| `VAULT_CACHE_TTL` | `300` | Cache trust window, in seconds. |
| `VAULT_HTTP_TIMEOUT` | `5` | Per-request HTTP timeout (seconds). |
| `VAULT_HTTP_RETRIES` | `3` | Bounded retry attempts. |
| `VAULT_TLS_VERIFY` | `true` | TLS certificate verification. **Never disable in production.** |
| `APP_KEY` | _(Laravel's)_ | Encrypts the local cache. **Deny-listed** — never sourced from Vault. |

A minimal `.env`:

```dotenv
VAULT_ENABLED=true
VAULT_ADDR=https://vault.example.com
VAULT_AUTH_MOUNT=approle
VAULT_ROLE_ID=...
VAULT_SECRET_ID=...
VAULT_SECRET_PATH=secret/data/my-app/production
VAULT_FAIL_OPEN=false
VAULT_CACHE_TTL=300

APP_KEY=base64:...   # already set by `php artisan key:generate`
```

All of your other secrets (DB password, API keys, …) live in Vault and resolve through
`env()` / `config()` unchanged.

**Precedence & the deny-list:** when a key exists in both `.env` and Vault, **Vault wins**
([ADR-0005](docs/adr/0005-secret-injection-rules.md)). The deny-list is absolute: the Loader will
never inject `APP_KEY`, `APP_ENV`, or any `VAULT_*` key, even if present in the Vault payload — it
logs a warning instead. These are *bootstrap-tier* keys that must exist before Vault can be reached.

**`key_map` (optional backstop):** if you cache config, prefer running `config:cache` at container
startup *after* injection (see [Production deployment](#production-deployment)). As an alternative,
`key_map` maps Vault keys to config paths so they survive a cached config:

```php
'key_map' => [
    'DB_PASSWORD' => 'database.connections.mysql.password',
],
```

## Usage

### Transparent (the default)

Nothing changes. Your existing code keeps reading secrets the way it always has:

```php
config('database.connections.mysql.password'); // resolves the Vault value
env('STRIPE_SECRET');                           // resolves the Vault value
```

### Runtime accessor

For explicit lookups, use the `Vault` facade:

```php
use Vaultenv\Vault\Facades\Vault;

Vault::get('STRIPE_SECRET');            // string|null
Vault::get('FEATURE_FLAG', 'default');  // with a default
Vault::all();                           // array<string, string>
Vault::refresh();                       // reload in-process (dev / cache-warm; NOT a prod hot-reload)
```

Or resolve the store via the container:

```php
public function __construct(private \Vaultenv\Vault\Secrets\SecretStore $secrets) {}
// $this->secrets->get('STRIPE_SECRET');
```

### Error handling

A fetch failure throws `Vaultenv\Vault\Exceptions\SecretProviderException`. The Vault driver's
`VaultException` extends it, so catching the parent covers both:

```php
use Vaultenv\Vault\Exceptions\SecretProviderException;

try {
    $value = Vault::get('STRIPE_SECRET');
} catch (SecretProviderException $e) {
    // log/handle — the message never contains a secret value
}
```

## Artisan commands

| Command | Purpose |
|---|---|
| `vault:install [--path=]` | Wire the boot hook into `bootstrap/app.php` (idempotent). |
| `vault:check [--gate]` | Diagnose connectivity and list secret keys (values masked). With `--gate`, exit code mirrors the Loader's success — for deploy scripts. |
| `vault:refresh` | Bust the cache and re-fetch. **Dev / cache-warm only** — not a production hot-reload. |

`vault:check` without `--gate` is a human diagnostic that always exits 0 and prints each step
(config, masked secret keys, cache status). `vault:check --gate` is **boot-equivalent**: it exits
non-zero **only if** the Loader would fail — i.e. no secrets are obtainable by any path (fresh
fetch, valid cache, or stale grace) — which makes it a correct deploy gate
([ADR-0010](docs/adr/0010-vault-check-gate-in-runsh.md)).

## Production deployment

Run the gate first, then cache config — and **only cache config after secrets are injected at
container startup, never at image-build time** ([ADR-0005](docs/adr/0005-secret-injection-rules.md)).
A representative container `run.sh`:

```sh
set -e

php artisan vault:check --gate     # halts a bad rollout; old pods keep serving
php artisan config:cache           # freezes config WITH the injected Vault values
php artisan route:cache
php artisan view:cache

# start your server (php-fpm / octane / supervisord / …)
```

**Failure posture:**

- **Cold start** — no secrets obtainable from memory *or* cache → **fail-closed**: the process
  throws and exits non-zero. Your orchestrator keeps the previous healthy pods running and the
  rollout halts ([ADR-0003](docs/adr/0003-fail-closed-by-default-in-production.md)).
- **Refresh blip** — a worker recycles and a re-fetch fails, but a usable (even expired) cache
  exists → **stale-while-revalidate grace**: serve last-known-good, log loudly, keep serving
  ([ADR-0004](docs/adr/0004-stale-while-revalidate-grace-on-refresh.md)).

**Rotation is a deployment action, never a runtime one** — update the secret in Vault, then do a
rolling restart so each fresh worker picks it up ([ADR-0011](docs/adr/0011-octane-worker-lifetime-no-reset-no-scrubbing.md)).

> **Observability (v1):** the v1 observability surface is the dedicated `vault` **log channel**
> (auto-registered to `storage/logs/vault.log` if you don't define one). It logs event names, key
> names, and counts — **never values**. Laravel events for secret lifecycle are intentionally
> deferred ([ADR-0007](docs/adr/0007-operational-hardening-resilience-observability-cache.md)),
> because the only fetch happens on the facade-free boot path where the event dispatcher doesn't
> exist yet.

## Laravel Octane & long-running workers

The model is **immutable-per-worker**: resolve secrets once at worker boot, inject, freeze, and
never mutate again in that process. There is **no mutable static state** anywhere, so nothing leaks
or bleeds between workers ([ADR-0011](docs/adr/0011-octane-worker-lifetime-no-reset-no-scrubbing.md)).

**Do**

- Let secrets resolve once per worker boot and treat them as read-only for the worker's lifetime.
- Rotate by rolling restart (`kubectl rollout restart`, a new deploy, etc.).
- Allow `putenv()` to run once at boot, before any coroutine spawns.

**Don't**

- Don't add a per-request Octane reset/refresh listener — it would force a Vault round-trip per request.
- Don't mutate `$_ENV` / `config()` / already-built singletons mid-request.
- Don't call `putenv()` per-request inside Swoole coroutines (not coroutine-safe).
- Don't TTL-refresh inside a live worker.

Audited safe under both PHP-FPM and Octane. **Per-request cost is zero** — no Vault traffic and no
file I/O on the hot path.

## Caching

- **Where:** `storage/framework/vault/secrets.cache` (directory `0700`, file `0600`). On
  Kubernetes, back this with a memory `emptyDir` (tmpfs) so it never touches node disk.
- **Encryption:** AES-256-CBC via your `APP_KEY`. This is defense-in-depth against accidental
  exposure — *not* a defense against a fully-compromised pod.
- **TTL is a trust window, not a refresh timer.** A running worker never re-reads it; the cache TTL
  just bounds how long a value is trusted before the *next* boot tries a refresh.
- **Why a file and not Redis?** Your Redis credentials may themselves live in Vault — a Redis-backed
  secret cache would be a chicken-and-egg problem. The file cache needs only `APP_KEY`, which exists
  before Vault is ever contacted.
- Set `VAULT_CACHE_ENABLED=false` to swap in a no-op `NullCache`.

## Security model

| Threat | Control |
|---|---|
| Secret values leaking into logs/traces | Event/key names only; DTOs are `readonly`; values are never logged or stringified. |
| `APP_KEY` / `VAULT_*` overwritten from Vault → boot loop | Hard deny-list in the injector. |
| Stale `.env` shadowing a real secret | Vault-wins precedence. |
| Cache file accidentally exposed | Encrypted + `0600` + tmpfs. |
| Vault outage during a deploy | Fail-closed gate halts the rollout; old pods keep serving. |
| Transient blip during a worker recycle | Stale-while-revalidate grace. |

**The bootstrap credential ("secret-zero").** Your `VAULT_SECRET_ID` is the credential that, with
the role id, mints a Vault token. Scope its AppRole policy to least privilege (only this service's
paths), enable a Vault audit device with alerting, and rotate on a regular cadence and on any
suspected compromise.

**Honest limits.** Injecting secrets into the application process means a **fully-compromised pod**
(an attacker with both `APP_KEY` and filesystem access) can decrypt the cache and read the injected
environment. This is inherent to in-app injection. The package materially shrinks the attack
surface and enables rotation, audit, and least-privilege — but for the strongest posture, move the
bootstrap credential to runtime injection (e.g. Kubernetes secrets, or a Workload-Identity flow
that eliminates secret-zero entirely). See
[ADR-0008](docs/adr/0008-accepted-risk-secret-zero-baked-into-image.md) and
[ADR-0009](docs/adr/0009-secret-id-per-environment-not-per-service.md).

## Extending: custom secret providers

The public extensibility seam is a single-method contract
([ADR-0006](docs/adr/0006-secretprovider-interface-single-vault-driver.md)):

```php
namespace Vaultenv\Vault\Contracts;

interface SecretProvider
{
    /**
     * @return array<string, string>
     * @throws \Vaultenv\Vault\Exceptions\SecretProviderException
     */
    public function fetch(): array;
}
```

It returns a flat key/value map and deliberately leaks **no** backend specifics (leases, KV
versions, auth methods). v1 ships exactly one implementation, `VaultSecretProvider`, bound behind
the `secrets.provider` config key. To use your own, implement the contract and bind it:

```php
$this->app->bind(\Vaultenv\Vault\Contracts\SecretProvider::class, MyProvider::class);
```

## Stability & SemVer — the frozen consumer surface

SemVer is measured against a small, frozen **consumer surface** only:

1. The single `bootstrap/app.php` boot line.
2. The `VAULT_*` / `APP_KEY` environment keys.
3. `Vault::get()` and the `SecretProvider` contract.
4. The published `config/vault.php` keys.
5. The artisan commands (`vault:install`, `vault:check`, `vault:refresh`).

Everything else (the Guzzle client, cache internals, retry logic) is an implementation detail and
may change in a minor/patch release. **Pin `^1.0`** — never `dev-*` or `*`. A behavior slated for
removal is deprecated (with a logged warning) in a minor release and removed only in the next major.

## Architecture overview

All classes live under the `Vaultenv\Vault\` namespace (`src/`).

| Component | Path | Responsibility |
|---|---|---|
| `VaultServiceProvider` | `src/VaultServiceProvider.php` | Auto-discovered; runtime wiring, commands, `key_map` backstop. |
| `VaultBootstrap` (the Loader) | `src/Bootstrap/VaultBootstrap.php` | Facade-free boot injection. |
| `VaultConfig` | `src/Config/VaultConfig.php` | Typed, validated projection of config (from env at boot, from `config()` at runtime). |
| `VaultFactory` | `src/Factory/VaultFactory.php` | Assembles client → auth → cache → provider → store. |
| `SecretProvider` / `VaultSecretProvider` | `src/Contracts`, `src/Provider` | Public contract + the v1 Vault driver. |
| `VaultClient` / `GuzzleVaultClient` | `src/Contracts`, `src/Http` | HTTP seam + Guzzle impl (retries, backoff, jitter, timeout). |
| `AuthMethod` / `AppRoleAuth` | `src/Contracts`, `src/Auth` | Auth seam + AppRole impl. |
| `SecretCache` / `EncryptedFileCache` / `NullCache` | `src/Contracts`, `src/Cache` | Cache seam + encrypted-file and no-op impls. |
| `SecretStore` | `src/Secrets/SecretStore.php` | Per-worker, write-once, read-only holder behind the facade. |
| `EnvInjector` | `src/Secrets/EnvInjector.php` | Writes `$_ENV`/`$_SERVER`/`putenv`; enforces the deny-list. |
| `VaultToken` / `VaultSecret` | `src/DTO` | Immutable `readonly` value objects. |
| `SecretProviderException` / `VaultException` | `src/Exceptions` | Contract-level + Vault-specific failures. |
| `FileLogger` | `src/Support/FileLogger.php` | Minimal PSR-3 logger for the facade-free boot path. |

## Development & testing

This is a TDD-first codebase (red → green → refactor), with coverage scoped to `src/`
(target ≥ 80%; currently 66 tests green, ~87% line coverage).

```bash
composer install
vendor/bin/phpunit                 # full suite
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Feature
```

Unit tests run with no network (Guzzle `MockHandler`); Feature tests boot a kernel via Orchestra
Testbench. The two test suites are `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`).

## Documentation & ADRs

Deeper reading for maintainers:

- **[DESIGN.md](DESIGN.md)** — the full build blueprint (architecture, lifecycle, invariants).
- **[CONTEXT.md](CONTEXT.md)** — the project glossary (secret-zero, the Loader, grace, the gate, …).
- **[docs/adr/](docs/adr/)** — the architecture decision records:

| ADR | Decision |
|---|---|
| [0001](docs/adr/0001-vault-package-targets-laravel-9-php-82.md) | Target Laravel 9+/PHP 8.2; no Lumen |
| [0002](docs/adr/0002-hybrid-bootstrap-no-zero-touch.md) | Hybrid bootstrap; no zero-touch |
| [0003](docs/adr/0003-fail-closed-by-default-in-production.md) | Fail-closed by default in production |
| [0004](docs/adr/0004-stale-while-revalidate-grace-on-refresh.md) | Stale-while-revalidate grace on refresh |
| [0005](docs/adr/0005-secret-injection-rules.md) | Secret injection rules (Vault-wins, deny-list, `config:cache` timing) |
| [0006](docs/adr/0006-secretprovider-interface-single-vault-driver.md) | `SecretProvider` interface, single Vault driver |
| [0007](docs/adr/0007-operational-hardening-resilience-observability-cache.md) | Operational hardening (no breaker, observability, cache) |
| [0008](docs/adr/0008-accepted-risk-secret-zero-baked-into-image.md) | Accepted risk: secret-zero handling |
| [0009](docs/adr/0009-secret-id-per-environment-not-per-service.md) | Secret id per-environment, not per-service |
| [0010](docs/adr/0010-vault-check-gate-in-runsh.md) | `vault:check` gate, boot-equivalent |
| [0011](docs/adr/0011-octane-worker-lifetime-no-reset-no-scrubbing.md) | Octane worker lifetime: no reset, no scrubbing |

## Contributing

Contributions are welcome. Please:

- Follow **TDD** — write the failing test first, then implement, then refactor.
- Keep coverage at **≥ 80%** on `src/`.
- Use **conventional commit** messages (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`).
- Don't contradict an existing ADR without recording a superseding one.

## License

Released under the [MIT License](LICENSE).
