# Hybrid bootstrap: one automated bootstrap edit + auto-discovered provider

**Context:** The package must inject Vault secrets into `$_ENV` *before* `LoadConfiguration` runs, so existing `env()`/`config()` consumers transparently see Vault values. But Composer package auto-discovery registers service providers at `RegisterProviders` — *after* config is already built from env. Pre-config injection and zero-touch auto-discovery are therefore mutually exclusive.

**Decision:** Hybrid, and we explicitly reject "zero-touch."
- **Pre-config injection** happens via a single `afterBootstrapping(LoadEnvironmentVariables)` hook in each service's `bootstrap/app.php`. A shipped `php artisan vault:install` command patches that file automatically and prints a diff — so onboarding is one command, not a hand edit.
- **An auto-discovered ServiceProvider** handles everything that can legitimately run late: the runtime `Vault::get()` accessor, Octane worker hooks, the `vault:check` / `vault:refresh` commands, and a defensive post-boot `config()` reconciliation (`key_map`) as a backstop for `config:cache`.

**Rejected:** Pure auto-discovery with post-config `config()` overwrites (option B). It silently fails for code that reads `env()` directly (including some Laravel internals) and anything evaluated during config load — a class of hard-to-debug "this one value is empty in prod" bugs.
