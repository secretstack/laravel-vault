# ibid/laravel-vault — Design Document

> Centralized HashiCorp Vault secret management for the IBID Laravel fleet (30+ services).
> Status: design locked across ADR-0001…0010. This document is the build blueprint.

---

## 0. How to read this

The *decisions* and their rationale live in Architecture Decision Records (ADR-0001…0010) in `docs/adr/` alongside this document. This document is the *consolidated blueprint*: it says **what to build and how the pieces fit**, and cross-references ADRs for "why". If a sentence here surprises you, the matching ADR explains it.

---

## 1. Purpose & scope

**Problem.** Every IBID service keeps its secrets as plaintext in a `.env` that is fetched from a config repo (`Ibid_Env`) and **baked into the image**. Goal: move secrets into Vault, fetch them at boot, and feed them to existing `env()`/`config()` consumers **transparently** — so a service adopts the package with near-zero code changes.

**In scope (v1):** Laravel 9/10/11 on PHP 8.2+; HashiCorp Vault KV-v2 via AppRole; transparent boot-time injection + a `Vault::get()` accessor; encrypted per-pod cache; Octane/Swoole/FrankenPHP/RoadRunner safety; fail-closed production posture with stale-while-revalidate grace.

**Out of scope (v1):** Lumen (ADR-0001); dynamic/leased DB credentials; a Vault Agent sidecar (roadmap); multi-provider machinery (ADR-0006); AKS Workload Identity auth (roadmap — kills secret-zero).

---

## 1.1 Why this package — DX & security

Why a *package*, and not "each team integrates Vault themselves" or "keep the `.env` model"? Two axes: developer experience and security. The short version: **the hard parts of doing this correctly are subtle, identical across all 30 services, and dangerous to get wrong — so they should be solved once, centrally, and tested once.**

### 1.1.1 Developer experience (DX)

**The cost of NOT having a package.** A correct integration is not trivial code. The stockv2 pilot is ~13 files of *bootstrap-lifecycle-sensitive* logic: a facade-free loader that must run between `LoadEnvironmentVariables` and `LoadConfiguration`, raw-Guzzle auth with retry/backoff/jitter, an `APP_KEY`-encrypted cache, Octane-safe (no-static) state handling, a deny-list, and fail-closed/grace semantics. Hand-rolling that in 30 services means:

- **30× the bug surface.** A flaw in the boot timing, the cache, or the Octane state model is re-derived (and re-broken) 30 different ways.
- **30× the maintenance.** When we fix the deny-list (ADR-0005) or the grace path (ADR-0004), we'd have to patch 30 repos by hand. With a package, it's one `composer update`.
- **Drift.** "Works on stockv2, breaks on serviceX" because each team implemented it slightly differently. No two `.env`-handling shims would be identical.

**What the package gives a consuming team instead:**

| DX property | How |
|---|---|
| **Near plug-and-play onboarding** | `composer require` + `php artisan vault:install` (auto-patches `bootstrap/app.php`) + bootstrap-tier `.env`. No Vault internals to learn. |
| **Zero application refactor** | Existing `config('database.password')` / `env('JWT_SECRET')` calls are unchanged — transparent injection (ADR-0002). Teams don't rewrite a single consumer. |
| **One mental model, fleet-wide** | Same `vault:check` / `vault:refresh` / `vault:install` commands and the same failure semantics on every service. On-call learns it once. |
| **Fast, safe local loop** | Composer *path repository* (§2.2) symlinks the package for instant iteration; `VAULT_ENABLED=false` makes it a no-op for local dev. |
| **Clear failure diagnostics** | `vault:check` prints auth/read/cache/inject status with masked values, instead of an opaque "DB connection refused" three layers deep. |
| **Centralized upgrades** | Bug fixes and hardening ship as a version bump behind a frozen consumer surface (§13) — internals improve without touching 30 `bootstrap/app.php` files. |

### 1.1.2 Security

**What the `.env`-baked model costs us today** (see §1.5.1 for the flow):

- **Plaintext sprawl.** Every secret for every service sits in plaintext in the `Ibid_Env` repo. Repo read access = the entire fleet's secrets.
- **Secrets in every image layer.** `COPY .env` bakes the full secret set into images. Anyone who can pull an image extracts everything.
- **No audit.** Nothing records who read which secret, when. Incident forensics is blind.
- **Rotation is so expensive it doesn't happen.** Rotating a secret means editing the config repo and rebuilding + redeploying the image. Because it's painful, secrets are long-lived — the single biggest real-world risk amplifier.

**What the package changes:**

| Security property | Before (`.env`) | With the package |
|---|---|---|
| **Single source of truth** | 30 scattered `.env` files | one Vault path per service/env, access-controlled |
| **Secrets in config repo** | all, plaintext | none (bootstrap-tier only) |
| **Secrets in image layers** | all | only secret-zero + `APP_KEY` (accepted, ADR-0008) |
| **Audit trail** | none | Vault audit device — every read logged (ADR-0009) |
| **Rotation** | repo edit + rebuild + redeploy | update Vault + rolling restart — cheap enough to *actually do* |
| **Least privilege** | n/a | per-env AppRole, path-scoped policy (ADR-0009) |
| **Blast radius of an image leak** | that service's secrets | contained to one environment (ADR-0009) |
| **Wrong/empty creds on outage** | n/a | fail-closed — refuses to serve rather than run on null secrets (ADR-0003) |
| **Bootstrap-key poisoning** | n/a | deny-list blocks `APP_KEY`/`VAULT_*` injection (ADR-0005) |
| **Secrets in logs** | ad-hoc | contract: event/key names only, never values (ADR-0007) |
| **Standardization** | 30 bespoke handlings of varying quality | one audited, reviewed implementation everywhere |

**Honest limits (so this isn't oversold).** The package does **not** make a fully-compromised pod safe — an attacker with `APP_KEY` and filesystem access can decrypt the cache and read injected env (inherent to in-app injection). And `VAULT_SECRET_ID` is still baked into the image in v1 (ADR-0008). It materially *shrinks* the attack surface and *enables* practices that were previously impractical (rotation, audit, least-privilege); the remaining gaps have a documented exit path (Workload Identity, §19). This is a large step forward, not a finish line.

---

## 1.2 Pros & cons (the trade-off, stated plainly)

§1.1 makes the case *for*. This section is the balanced ledger — what you gain, and what you are **signing up to operate**. Adopting this package is a real trade, not a free win.

### Pros (summary — detail in §1.1)

- **Secrets centralized and access-controlled** in Vault; out of the config repo and (mostly) out of image layers.
- **Audit trail** of every secret read (ADR-0009).
- **Cheap rotation** — update Vault + rolling restart, no rebuild — which is what finally makes rotation *happen*.
- **Least privilege** per environment (ADR-0009).
- **Zero application refactor** — existing `config()`/`env()` calls are unchanged (ADR-0002).
- **One-command onboarding** and **fleet-wide consistency** — one tested implementation, not 30 bespoke ones.
- **Centralized fixes** behind a frozen consumer surface (§13).
- **Safe-by-default runtime** — Octane-safe (immutable-per-worker), fail-closed, stale-grace, zero per-request cost.

### Cons / trade-offs you are signing up for

| Trade-off | Why it exists | Mitigation / ref |
|---|---|---|
| **A new boot-time critical dependency** | Pods now need Vault reachable at cold start; before, the baked `.env` was always present. A Vault outage during a deploy halts that rollout. | Fail-closed keeps old pods serving; stale-grace covers warm pods; Vault-up-during-deploy is an accepted constraint (ADR-0003) |
| **Operational surface grows** | DevOps must provision and run Vault auth: AppRole creds, per-env policies, audit, rotation. More to understand and maintain. | One-time per service/env; standardized checklist (§17) |
| **Secret-zero not eliminated** | `VAULT_SECRET_ID` + `APP_KEY` are still baked into the image in v1. | Accepted risk + compensating controls; documented exit path (ADR-0008, §19) |
| **Rotation needs a restart** | Secrets are immutable for a worker's life (the thing that makes it Octane-safe). No hot reload. | Rolling restart; piggybacks on normal deploys (Q5). Dynamic secrets are out of scope (v1) |
| **A bad package version can stop pods booting** | It's bootstrap-tier, not runtime — a throwing loader = pods won't start. | Strict `^1.0` pinning + canary-first rollout + the `run.sh` gate (§13, ADR-0010) |
| **Added boot latency** | One AppRole login + KV read per cold pod (~50–200ms); deploy storms add Vault load. | One-shot per pod, cached after; jitter to avoid stampede (§15) |
| **Weaker test loop than ideal** | CI is network-locked, so the package can't be integration-tested against a real Vault in CI. | Mocked unit tests + `run.sh --gate` at deploy + manual staging smoke (§14) |
| **A new operational rule to enforce** | `config:cache` must run at *startup-after-injection*, never at build time, or values silently go null. | Shipped startup sequence + `vault:install` verification (ADR-0005) |
| **Cache encryption is not a strong control** | Defense-in-depth only; a fully-compromised pod (has `APP_KEY` + fs) can still read secrets. | Inherent to in-app injection; Workload-Identity roadmap (§19) |
| **Coordinated fleet migration** | 30 services need provisioning, `.env` slimming, and canary waves. | Phased runbook (§17) |

### When the trade-off may *not* be worth it

- A service with **no real secrets** (only public URLs/flags) — the new dependency buys little. Leave it on `.env`, or set `VAULT_ENABLED=false`.
- A throwaway/spike service with a short lifespan.
- A service whose production target **cannot tolerate a boot-time Vault dependency** *and* cannot run the `run.sh` gate — revisit fail-open posture first.

### Net assessment

For a revenue-critical, long-lived service holding real credentials (i.e. most of the fleet), the trade is **clearly worth it**: you exchange "secrets sprawled in plaintext, never rotated, unauditable" for "centralized, audited, rotatable secrets" at the cost of a managed boot-time dependency and upgrade discipline — both of which are addressed by the safety mechanisms above. The cons are real and must be operated, not hand-waved; none of them outweighs the security posture change for services that actually hold secrets.

---

## 1.5 How it works: existing (`.env`) vs new (Vault)

This is the heart of "what changes." The mechanism by which `config('db.password')` gets its value is *almost identical* in both — the difference is **where the value comes from and where it is exposed.**

### 1.5.1 Existing flow — `.env` baked into the image

```
┌─ Ibid_Env repo ────────────────────────────────────────────┐
│  <service>/.env   ← FULL plaintext secrets (DB_PASSWORD,    │
│                     REDIS_PASSWORD, JWT_SECRET, keys, …)     │
└───────────────┬─────────────────────────────────────────────┘
                │ Jenkins stage: "Get Latest .env"
                ▼
┌─ Docker build ─────────────────────────────────────────────┐
│  COPY .env /app/.env     ← ALL secrets frozen into an       │
│                            image layer (anyone who can pull  │
│                            the image can read every secret)  │
│  Build & Push → Container Registry                          │
└───────────────┬─────────────────────────────────────────────┘
                │ ArgoCD deploy
                ▼
┌─ Pod startup (run.sh) ─────────────────────────────────────┐
│  config:cache  → config/*.php evaluates env('DB_PASSWORD')  │
│                  → reads the baked .env value, freezes it    │
└───────────────┬─────────────────────────────────────────────┘
                ▼
   Application:  config('database.connections.…​.password')
                  └─► plaintext value that originated in .env
```

**Properties:** secrets live in the config repo *and* in every image layer; rotation = edit `Ibid_Env` + full rebuild + redeploy; no record of who read what; an image leak exposes that service's entire secret set.

### 1.5.2 New flow — Vault fetched at boot, injected transparently

```
┌─ Vault (KV-v2) ────────────────────────────────────────────┐
│  ibid/data/<service>/<env>   ← the real secrets             │
└───────────────▲─────────────────────────────────────────────┘
                │ AppRole login + KV-v2 read (at pod boot)
                │
┌─ Ibid_Env repo ┴───────────────────────────────────────────┐
│  <service>/.env   ← SLIMMED to bootstrap-tier ONLY:         │
│      VAULT_ADDR, VAULT_AUTH_MOUNT, VAULT_SECRET_PATH, flags  │
│      VAULT_ROLE_ID, VAULT_SECRET_ID (secret-zero), APP_KEY   │
│      APP_ENV, APP_URL  ← no app secrets anymore              │
└───────────────┬─────────────────────────────────────────────┘
                │ "Get Latest .env" → COPY .env /app/.env
                │ (only secret-zero + APP_KEY baked — ADR-0008)
                ▼
┌─ Pod startup ──────────────────────────────────────────────┐
│  bootstrap/app.php                                          │
│    afterBootstrapping(LoadEnvironmentVariables):            │
│      VaultBootstrap::inject($app)                           │
│        ├─ read VAULT_* + APP_KEY (bootstrap-tier)           │
│        ├─ cache HIT?  → use it   |  MISS → AppRole+KV read  │
│        ├─ inject secrets into $_ENV  (deny-list enforced)   │
│        └─ write encrypted cache (tmpfs)                     │
│  run.sh: vault:check --gate → config:cache                 │
│            → config/*.php evaluates env('DB_PASSWORD')      │
│              → now reads the Vault-INJECTED value, freezes  │
└───────────────┬─────────────────────────────────────────────┘
                ▼
   Application:  config('database.connections.…​.password')
                  └─► value that originated in Vault — SAME call,
                      no application code change
```

**Key insight:** `config('…password')` and `env('…')` are unchanged in the application. The package just makes sure that *by the time config loads*, `$_ENV` already holds the Vault value (ADR-0002). The app cannot tell the difference — that is the whole point of "transparent."

### 1.5.3 Side-by-side

| Aspect | Existing (`.env`) | New (Vault) |
|---|---|---|
| Source of truth | `Ibid_Env` repo `.env` | Vault path `ibid/data/<svc>/<env>` |
| In the config repo | **all** secrets, plaintext | bootstrap-tier only |
| In the image layer | **all** secrets | secret-zero + `APP_KEY` only (ADR-0008) |
| How `config()` gets the value | `env()` from baked `.env` | `env()` from Vault-injected `$_ENV` |
| Application code change | — | **1 line** in `bootstrap/app.php` (via `vault:install`) |
| Rotation | edit repo → rebuild → redeploy | update Vault → rolling restart (Q5) |
| Audit of secret reads | none | Vault audit device (ADR-0009) |
| Image-leak blast radius | that service's secrets | that **environment's** secrets (ADR-0009) |
| Behavior if source unreachable | n/a (always baked in) | fail-closed + stale grace (ADR-0003/0004) |
| Per-request cost | zero | zero (cached in memory; §15) |

### 1.5.4 Concrete: the stockv2 pilot

- **Before:** `.env` ≈ 120 lines, 87 real secret/config values baked into the image.
- **After:** 87 values moved to Vault path `ibid/data/ims/dev/stockv2` (KV-v2, version 2); `.env` slimmed to ~16 bootstrap-tier keys. `config('database')`, `config('cache')`, etc. resolve unchanged — verified live via `vault:check`.
- **Does NOT change (yet):** `VAULT_SECRET_ID` and `APP_KEY` are still in the baked `.env` (ADR-0008 accepted risk); the exit path is runtime K8s injection / Workload Identity (§19).

---

## 2. Package-authoring primer (first package? start here)

A Composer package is just a folder with a `composer.json` that declares a **PSR-4 namespace** and (for Laravel) **auto-discovers a service provider**. Nothing magic.

### 2.1 `composer.json` anatomy

```jsonc
{
  "name": "ibid/laravel-vault",
  "description": "Centralized HashiCorp Vault secret management for IBID Laravel services.",
  "type": "library",
  "license": "proprietary",
  "require": {
    "php": "^8.2",
    "illuminate/support": "^9.0 || ^10.0 || ^11.0",
    "illuminate/encryption": "^9.0 || ^10.0 || ^11.0",
    "guzzlehttp/guzzle": "^7.0",
    "psr/log": "^1.0 || ^2.0 || ^3.0"
  },
  "require-dev": {
    "orchestra/testbench": "^7.0 || ^8.0 || ^9.0",
    "phpunit/phpunit": "^9.5 || ^10.0"
  },
  "autoload":      { "psr-4": { "Ibid\\Vault\\":       "src/" } },
  "autoload-dev":  { "psr-4": { "Ibid\\Vault\\Tests\\": "tests/" } },
  "extra": {
    "laravel": {
      "providers": [ "Ibid\\Vault\\VaultServiceProvider" ],
      "aliases":   { "Vault": "Ibid\\Vault\\Facades\\Vault" }
    }
  },
  "config": { "sort-packages": true },
  "minimum-stability": "stable"
}
```

- **`require`** uses OR-ranges (`^9.0 || ^10.0 || ^11.0`) so one package supports three Laravel majors. Depend on **`illuminate/*` components**, never `laravel/framework` — a package pulls only the pieces it needs.
- **`extra.laravel.providers`** is Laravel package auto-discovery: the consumer gets the provider registered automatically on `composer require`. (This is the *late* registration — see §4 for why it isn't enough on its own.)
- **`orchestra/testbench`** is how you boot a real Laravel kernel inside the package's own tests.

### 2.2 Testing the package against stockv2 *without publishing* (path repository)

While developing, point a consumer at your local folder. In `Ibid_ADMS_ServiceStock/composer.json`:

```jsonc
"repositories": [
  { "type": "path", "url": "../laravel-vault", "options": { "symlink": true } }
],
"require": { "ibid/laravel-vault": "@dev" }
```

Then inside the container: `composer require ibid/laravel-vault:@dev`. Because both folders are under `~/podman-volumes/www`, the container sees `../laravel-vault` and **symlinks** it — edit the package, the change is live in stockv2 instantly. This is the dev loop; you publish only when it's stable.

### 2.3 Publishing to private Packagist

1. Push the package to its own git repo (e.g. `git@…/ibid/laravel-vault.git`).
2. Submit the repo to your **private Packagist** (packagist.com) org.
3. Each release is a **git tag** (`v1.0.0`) — Packagist exposes tags as installable versions.
4. Consumers add the private Packagist repository to their `composer.json` and `composer require ibid/laravel-vault:^1.0`.

### 2.4 Versioning is git tags

SemVer (§13) maps directly to tags: `v1.0.0`, `v1.1.0` (new feature, BC), `v1.1.1` (fix), `v2.0.0` (breaking). Never move a published tag. A bad `v1.x` is fixed by `v1.x+1`, not by re-tagging.

---

## 3. Architecture overview & the load-bearing constraint

The hard fact that shapes everything (ADR-0002):

```
Laravel boot order:
  1. LoadEnvironmentVariables   ← .env parsed into $_ENV
  2. LoadConfiguration          ← config/*.php evaluated; env() frozen INTO config
  3. RegisterProviders          ← package auto-discovery registers our provider HERE
  4. BootProviders
```

Transparent injection must happen **between 1 and 2**. Auto-discovery happens at **3** — too late. Therefore the package has **two entry points into one codebase**:

- **The Loader** (facade-free) — invoked from a single line in the consumer's `bootstrap/app.php`, hooked on `afterBootstrapping(LoadEnvironmentVariables)`. Runs before facades/`config()` exist. Does the injection.
- **The ServiceProvider** (facade-rich) — auto-discovered at step 3. Binds the runtime accessor, commands, Octane hooks, events, and the defensive `key_map` config reconciliation.

Same `SecretProvider`/cache/client classes power both; only the *wiring* differs (raw `new` + Monolog at boot; container + `Log` facade at runtime).

The single bootstrap line is the **only** mandatory code change, and it's automated by `php artisan vault:install` (which patches `bootstrap/app.php` and prints a diff).

---

## 4. Folder structure

```
laravel-vault/
├── composer.json
├── README.md                      # install contract (the thing consumers actually read)
├── DESIGN.md                      # this file
├── CONTEXT.md                     # glossary (moved from pilot)
├── docs/adr/                      # ADR-0001…0010 (moved from pilot)
├── config/
│   └── vault.php                  # published config; all keys via env() with safe defaults
├── src/
│   ├── VaultServiceProvider.php   # auto-discovered; runtime wiring (§6)
│   ├── Bootstrap/
│   │   └── VaultBootstrap.php      # the Loader; static inject(Application $app): void
│   ├── Contracts/
│   │   ├── SecretProvider.php       # fetch(): array<string,string>
│   │   └── VaultClient.php          # login()/readKvV2() — HTTP seam
│   ├── Providers/
│   │   └── VaultSecretProvider.php  # the one SecretProvider impl (wraps VaultClient)
│   ├── Vault/
│   │   ├── GuzzleVaultClient.php    # raw Guzzle; retry/backoff/jitter/timeout (ADR-0007)
│   │   └── Auth/
│   │       └── AppRoleAuth.php       # internal auth seam (Azure/JWT added later)
│   ├── Cache/
│   │   └── EncryptedFileCache.php   # APP_KEY-encrypted; get/put/forget; grace-aware
│   ├── Secrets/
│   │   ├── SecretStore.php          # per-worker, write-once, read-only (Octane-safe)
│   │   └── EnvInjector.php          # writes $_ENV/$_SERVER/putenv; enforces deny-list
│   ├── DTO/
│   │   ├── VaultToken.php            # readonly
│   │   └── VaultSecret.php           # readonly
│   ├── Events/
│   │   ├── SecretsFetched.php
│   │   ├── SecretsServedStale.php
│   │   └── VaultUnreachable.php
│   ├── Console/
│   │   ├── CheckCommand.php          # vault:check [--gate]
│   │   ├── RefreshCommand.php        # vault:refresh
│   │   └── InstallCommand.php        # vault:install (patches bootstrap/app.php)
│   ├── Exceptions/
│   │   ├── VaultException.php
│   │   └── SecretProviderException.php
│   └── Facades/
│       └── Vault.php
└── tests/
    ├── Unit/                        # MockHandler-based, no network (§14)
    └── Feature/                     # Testbench: boots a kernel, asserts injection
```

---

## 5. Contracts

```php
namespace Ibid\Vault\Contracts;

interface SecretProvider
{
    /** @return array<string,string>  @throws SecretProviderException */
    public function fetch(): array;            // ADR-0006: flat map, no Vault-isms leak out
}

interface VaultClient
{
    public function login(string $roleId, string $secretId): \Ibid\Vault\DTO\VaultToken;     // throws VaultException
    public function readKvV2(string $path, string $clientToken): \Ibid\Vault\DTO\VaultSecret; // throws VaultException
}
```

`SecretProvider` is the public extensibility seam. `VaultClient` is the internal HTTP seam (swappable in tests; hosts the auth strategy). The contract returns `array<string,string>` and knows nothing about leases, KV-v2 versions, or AppRole.

---

## 6. ServiceProvider structure

```php
final class VaultServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vault.php', 'vault');

        // Facade-backed singletons (Log::channel('vault') available here)
        $this->app->singleton(VaultClient::class,    fn () => /* GuzzleVaultClient */);
        $this->app->singleton(SecretProvider::class, fn () => /* VaultSecretProvider */);
        $this->app->singleton(SecretStore::class,    fn () => /* per-worker store */);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/vault.php' => config_path('vault.php')], 'vault-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, RefreshCommand::class, InstallCommand::class]);
        }

        $this->reconcileCachedConfig();   // ADR-0005 key_map backstop for config:cache
        $this->registerOctaneHooks();     // §9
    }
}
```

`SecretStore` binding is a singleton but **holds no `static` state** (ADR / §9). The provider never performs the *primary* injection — that already happened in the Loader at boot.

---

## 7. Lifecycle & sequence

### 7.1 Cold pod boot (happy path)

```
bootstrap/app.php
  └─ afterBootstrapping(LoadEnvironmentVariables)
       └─ VaultBootstrap::inject($app)
            ├─ read VAULT_* + APP_KEY from Env (facade-free)
            ├─ EncryptedFileCache::get()  → MISS (ephemeral fs, fresh pod)
            ├─ SecretProvider::fetch()
            │     ├─ AppRoleAuth.login(role,secret)        → VaultToken
            │     └─ VaultClient.readKvV2(path,token)      → VaultSecret
            ├─ EncryptedFileCache::put(secrets, ttl)        # ttl=min(lease-skew, cache_ttl)
            └─ EnvInjector::inject(secrets)                 # deny-list enforced (ADR-0005)
  └─ LoadConfiguration   ← config/*.php now read injected env() values  ✓
  └─ RegisterProviders → VaultServiceProvider
```

### 7.2 Cold pod boot, Vault unreachable (ADR-0003)

```
cache MISS → fetch() throws → no secrets anywhere
  → fail_open=false (prod): throw → process exits non-zero
    → run.sh `set -e` aborts → pod won't start → rollout halts, old pods serve
```

### 7.3 Warm worker recycle, Vault blip (ADR-0004 grace)

```
worker recycles (max_requests) → new process re-runs Loader
  cache present but EXPIRED → attempt refresh → Vault blip → fetch() throws
    → usable (stale) cache exists → SERVE last-known-good
       + log cache.stale_served + event SecretsServedStale + Sentry breadcrumb
    → pod boots, keeps serving  ✓  (no false kill)
```

### 7.4 Deploy-time gate (ADR-0010)

```
run.sh:
  set -e
  php artisan vault:check --gate     # exit code mirrors Loader success (boot-equivalent)
  php artisan optimize:clear
  php artisan app:apim               # uses injected APP_ENV/APP_URL
  php artisan config:cache           # freezes config WITH Vault values (ADR-0005)
  php artisan route:cache && view:cache
  supervisord
```

---

## 8. Caching strategy

- **Where:** `storage/framework/vault/secrets.cache`, dir `0700` / file `0600`, mounted on a memory-backed `emptyDir` (tmpfs) so it never hits node disk (ADR-0007).
- **Encryption:** AES-256-CBC via `APP_KEY` — defense-in-depth against accidental exposure, *not* a pod-compromise control (ADR-0007).
- **TTL:** `min(lease_duration − skew, VAULT_CACHE_TTL)`. Default `VAULT_CACHE_TTL=2764800` (32d). TTL is a **trust window**, not a refresh timer — a running worker never re-reads it (Q5).
- **Grace:** on a *refresh* failure with a usable-but-expired cache → serve it (ADR-0004).
- **Why a file, not Redis:** Redis credentials may themselves come from Vault → chicken-and-egg. The file needs only `APP_KEY`, which exists before Vault is contacted.

---

## 9. Octane / Swoole / RoadRunner safety (do & don't)

The model is **immutable-per-worker** (ADR-0004 context, Q5): resolve once at worker boot, inject, freeze, never mutate again in that process.

**DO**
- Resolve secrets once per worker boot; hold them in a per-worker instance (`SecretStore`).
- Treat secrets as read-only for the worker's life.
- Rotate by **rolling restart** (ArgoCD deploy already restarts pods; otherwise `kubectl rollout restart`).
- Set `putenv()` **once at boot**, before any coroutine spawns.

**DON'T**
- ❌ No `static` mutable properties anywhere — they leak/share across workers (the classic Octane bug).
- ❌ Don't mutate `$_ENV`/`config()`/already-built singletons (DB/Redis managers) mid-request — they captured their secret at boot; you'd create half-old/half-new state.
- ❌ Don't call `putenv()` per-request inside Swoole coroutines (not coroutine-safe).
- ❌ Don't TTL-refresh inside a live worker. `vault:refresh` is a **dev/cache-warm tool**, never a prod hot-reload path.

**Octane container flush note:** if `octane.flush` is configured to flush our singletons between requests, re-resolution is cheap (it re-reads the in-process injected `$_ENV` / the tmpfs cache). Secrets correctness never depends on singleton persistence because the source of truth is the already-injected env, set at boot.

---

## 10. Security & threat analysis (summary)

| Threat | Control | Ref |
|---|---|---|
| Secret values in logs/traces | Log event names + key names only, never values; DTOs are `readonly`; no `__toString` of secrets | ADR-0007 |
| `APP_KEY`/`VAULT_*` overwritten from Vault → boot loop | Hard deny-list in `EnvInjector` | ADR-0005 |
| Secret-zero in image layer | **Accepted risk** + compensating controls (audit, registry RBAC, rotation) | ADR-0008 |
| One image leak → fleet-wide | `SECRET_ID` per-environment; env boundary holds; intra-env contained by audit | ADR-0009 |
| Stale `.env` shadows real secret | Vault-wins precedence | ADR-0005 |
| Cache file accidental exposure | Encrypted + `0600` + tmpfs | ADR-0007 |
| Vault outage during deploy | Fail-closed + gate halts rollout; old pods serve | ADR-0003/0010 |
| Transient blip kills healthy pod | Stale-while-revalidate grace | ADR-0004 |

**Threats explicitly NOT mitigated in v1:** a fully-compromised pod (attacker has `APP_KEY` and filesystem → can decrypt cache and read injected env). This is inherent to in-app secret injection and is the reason the Workload-Identity roadmap exists.

---

## 11. Configuration strategy

- One published `config/vault.php`; **every value via `env()` with a safe default** so `config:cache` is deterministic.
- `.env` (baked, from `Ibid_Env`) carries only **bootstrap-tier** keys: non-secret `VAULT_*` config + `VAULT_ROLE_ID` + (for now, ADR-0008) `VAULT_SECRET_ID` + `APP_KEY` + `APP_ENV`/`APP_URL` for build-time `app:apim`.
- Precedence: **Vault > .env** (ADR-0005). Runtime K8s env vars (where available) > baked `.env` automatically (phpdotenv doesn't overwrite set vars) — this is the zero-code exit path from ADR-0008.
- Namespaces: `VAULT_NAMESPACE` supported (Vault Enterprise); empty by default.

---

## 12. Observability contract (ADR-0007)

- **Logs** → dedicated `vault` channel, event names only: `login.ok|fail`, `fetch.ok|fail`, `cache.hit|miss`, `cache.stale_served`, `denylist.hit`, `inject.count`. **Never a value.**
- **Events** → `SecretsFetched`, `SecretsServedStale`, `VaultUnreachable` — services/SRE subscribe for metrics/alerts. No baked-in Prometheus client.
- **Sentry** → report on cold-fail; breadcrumb on stale-served.

---

## 13. Versioning & backward compatibility

**The frozen consumer surface (the real public API — Q12):**
1. the one `bootstrap/app.php` line (`VaultBootstrap::inject($app)`),
2. the `VAULT_*` / `APP_KEY` env keys,
3. `Vault::get()` + `SecretProvider`,
4. published `config/vault.php` keys,
5. artisan commands `vault:check|refresh|install`.

- **SemVer is defined against those five only.** Internal refactors (Guzzle client, cache internals, retry logic) are minor/patch.
- **Consumers pin `^1.0`** — never `dev-*`, never `*`.
- **The bootstrap line must be a dead-stable one-liner that delegates** — so internals evolve without re-editing 30 services. Changing it = a major + fleet-wide `vault:install` re-run (the most expensive change; design to never do it).
- **Deprecation policy:** a behavior slated for removal is deprecated in a minor (logged warning), removed only in the next major.

---

## 14. Testing strategy (CI cannot host Vault — Q12)

- **Unit (no network):** Guzzle `MockHandler` for `GuzzleVaultClient` (login parse, KV-v2 `data.data` parse, 403 no-retry, 5xx retry+backoff, namespace header); `EncryptedFileCache` round-trip / expiry / tamper / wrong-key / grace; `EnvInjector` deny-list; `SecretStore` write-once.
- **Feature (Testbench):** boot a kernel with the package, fake `SecretProvider`, assert env keys injected, assert no-op when `VAULT_ENABLED=false`, assert fail-closed throws and fail-open serves stale.
- **Gate semantics test:** assert `vault:check --gate` exit code == Loader success across {fresh, valid cache, stale grace, cold+down} (ADR-0010).
- **Smoke (manual, against staging):** since CI is network-locked, the real-Vault check is `vault:check` run in `run.sh` at deploy + a manual staging run during release. This is the explicit substitute for real-Vault-in-CI.
- **Coverage target:** ≥ 80% on `src/` (matches the org standard).

---

## 15. Benchmarking strategy

- **Cold-boot cost:** measure added boot latency = AppRole login + KV read (expect ~50–200ms one-shot per pod). Assert cache-hit boot adds < ~5ms (one decrypt).
- **Per-request cost:** must be **zero** — secrets are in memory; assert no Vault traffic and no file I/O on the hot path (the whole point of immutable-per-worker).
- **Deploy-storm:** model 30 services × N replicas cold-booting in a rollout window; confirm total login+read volume is well within Vault capacity (it is — hundreds of one-shot calls vs Vault's thousands/sec). Add jitter to avoid synchronized stampede.
- Track p50/p99 boot delta in the pilot before fleet rollout.

---

## 16. Production hardening checklist

- [ ] `VAULT_FAIL_OPEN=false` in prod (ADR-0003)
- [ ] `VAULT_TLS_VERIFY=true` (never disable in prod)
- [ ] Cache dir on tmpfs `emptyDir`; `0700`/`0600`
- [ ] `vault:check --gate` first in `run.sh`; `set -e` present
- [ ] `config:cache` at startup, **after** injection — never at build (ADR-0005)
- [ ] No secret in any baked layer except the accepted secret-zero/`APP_KEY` (ADR-0008)
- [ ] `APP_KEY` removed from the Vault payload (ADR-0005 action item)
- [ ] Per-env `SECRET_ID`; policy scoped to `ibid/data/+/<env>/*` (ADR-0009 — DevOps)
- [ ] Vault audit device + anomaly alerting on (ADR-0009 — DevOps)
- [ ] Chat-exposed dev `SECRET_ID` rotated (ADR-0008)
- [ ] Consumer pins `ibid/laravel-vault:^1.0`
- [ ] `vault` log channel wired; Sentry receiving cold-fail reports

---

## 17. Migration runbook — 30+ services

**Per-service onboarding checklist:**
1. DevOps: create Vault path `ibid/data/<service>/<env>`, path-scoped policy, bind to the per-env AppRole.
2. Populate the path (bulk JSON in the Vault UI — the `.env → JSON` converter, excluding `VAULT_*` and `APP_KEY`).
3. `Ibid_Env`: slim the service's `.env` to bootstrap-tier keys only.
4. `composer require ibid/laravel-vault:^1.0`.
5. `php artisan vault:install` (patches `bootstrap/app.php`, prints diff).
6. Add `vault:check --gate` to `run.sh`; ensure `config:cache` runs after it.
7. Deploy to dev → confirm `vault:check` passes, app boots, one canary secret resolves.
8. Gradually blank the corresponding plaintext `.env` values (grace covers gaps during transition).
9. Promote to staging, then prod with `VAULT_FAIL_OPEN=false`.

**Fleet sequencing:**
- **Wave 0 — pilot:** stockv2 (already proven). Bake the package from it.
- **Wave 1 — canaries:** 2–3 low-risk services, one per release, 1-day soak each.
- **Wave 2 — bulk:** remaining services in small batches; never a big-bang fleet `composer update`.
- **Gate between waves:** zero boot failures, zero `cache.stale_served` anomalies, Vault audit clean.

---

## 18. Deployment strategy

- Package release = git tag → private Packagist (§2.3).
- Consumer upgrades are deliberate: bump the `^1.x` constraint, canary first.
- The `run.sh` ordering (§7.4) is the mandatory startup contract shipped/documented by the package; `vault:install` can also scaffold/verify it.
- Rollback: a bad package version → revert the consumer's constraint + redeploy; old pods kept serving throughout because the gate halts bad rollouts.

---

## 19. Open items / external dependencies

- **DevOps (prod go-live gate):** per-env policy scoped to IBID paths; Vault audit + alerting (ADR-0009).
- **DevOps (hygiene):** rotate chat-exposed dev `SECRET_ID` (ADR-0008).
- **Pilot code action:** remove `APP_KEY` from the stockv2 Vault path (ADR-0005).
- **Roadmap:** Vault Agent sidecar / AKS Workload Identity (eliminates secret-zero); dynamic DB secrets.

---

## 20. ADR index

| ADR | Title |
|---|---|
| 0001 | Laravel 9+/PHP 8.2; no Lumen |
| 0002 | Hybrid bootstrap; no zero-touch |
| 0003 | Fail-closed by default in production |
| 0004 | Stale-while-revalidate grace on refresh |
| 0005 | Secret injection rules (Vault-wins, deny-list, config:cache at startup) |
| 0006 | SecretProvider interface, single Vault driver |
| 0007 | Operational hardening (no breaker, observability, cache) |
| 0008 | Accepted risk: secret-zero baked into image |
| 0009 | SECRET_ID per-environment, not per-service |
| 0010 | vault:check gate in run.sh, boot-equivalent |
